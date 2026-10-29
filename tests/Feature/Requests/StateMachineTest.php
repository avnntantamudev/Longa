<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\StateMachine\InvalidTransition;

/*
| Matriz completa das transições (BISCA, Fase 2.2): cada par (de, para) é permitido ou não exatamente como em
| docs/state-machines.md, aplicada também na base de dados e registada no histórico do pedido.
*/

$requestAllowed = [
    'created' => ['searching'],
    'searching' => ['accepted', 'no_provider', 'cancelled'],
    'no_provider' => ['searching', 'cancelled'],
    'accepted' => ['on_the_way', 'cancelled'],
    'on_the_way' => ['diagnosing', 'in_progress', 'cancelled'],
    'diagnosing' => ['quote_sent'],
    'quote_sent' => ['quote_accepted', 'diagnosing', 'cancelled'],
    'quote_accepted' => ['in_progress'],
    'in_progress' => ['completed', 'disputed'],
    'completed' => ['paid', 'disputed'],
    'disputed' => ['paid', 'cancelled'],
    'paid' => [],
    'cancelled' => [],
];

$offerAllowed = [
    'sent' => ['viewed', 'accepted', 'declined', 'expired', 'lost'],
    'viewed' => ['accepted', 'declined', 'expired', 'lost'],
    'accepted' => [],
    'declined' => [],
    'expired' => [],
    'lost' => [],
];

it('define as transições do pedido', function () use ($requestAllowed) {
    foreach (RequestState::cases() as $from) {
        foreach (RequestState::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(in_array($to->value, $requestAllowed[$from->value], true), "{$from->value} → {$to->value}");
        }
    }
});

it('define as transições da oferta', function () use ($offerAllowed) {
    foreach (OfferState::cases() as $from) {
        foreach (OfferState::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(in_array($to->value, $offerAllowed[$from->value], true), "{$from->value} → {$to->value}");
        }
    }
});

it('aplica transições válidas e grava na base de dados', function () {
    $request = ServiceRequest::factory()->create();

    $request->transitionTo(RequestState::Searching, ['expires_at' => now()->addMinutes(5)]);

    expect($request->state)->toBe(RequestState::Searching)
        ->and($request->fresh()?->state)->toBe(RequestState::Searching)
        ->and($request->fresh()?->expires_at)->not->toBeNull();
});

it('aplica cada transição válida na base de dados e regista-a no histórico', function () use ($requestAllowed) {
    foreach ($requestAllowed as $from => $targets) {
        foreach ($targets as $to) {
            // Diagnóstico só nos orçamentos; "a caminho → em execução" só nos outros modos
            $mode = $from === 'on_the_way' && $to === 'in_progress' ? PricingMode::Fixed : PricingMode::Quote;
            $request = ServiceRequest::factory()->create(['pricing_mode' => $mode]);
            $request->forceFill(['state' => $from])->save();

            $request->by(null, 'teste')->transitionTo(RequestState::from($to));

            $last = $request->transitions()->get()->last();
            expect($request->fresh()?->state)->toBe(RequestState::from($to), "{$from} → {$to}")
                ->and([$last?->from_state, $last?->to_state, $last?->reason])->toBe([RequestState::from($from), RequestState::from($to), 'teste']);
        }
    }
});

it('recusa cada transição inválida sem alterar nada nem registar no histórico', function () use ($requestAllowed) {
    foreach (RequestState::cases() as $from) {
        foreach (RequestState::cases() as $to) {
            if (in_array($to->value, $requestAllowed[$from->value], true)) {
                continue;
            }
            $request = ServiceRequest::factory()->create(['pricing_mode' => PricingMode::Quote]);
            $request->forceFill(['state' => $from])->save();
            $history = $request->transitions()->count();

            expect(fn () => $request->transitionTo($to))->toThrow(InvalidTransition::class);
            expect($request->fresh()?->state)->toBe($from, "{$from->value} → {$to->value}")
                ->and($request->transitions()->count())->toBe($history);
        }
    }
});

it('nos serviços de preço fixo e por hora salta o diagnóstico e o orçamento', function (PricingMode $mode) {
    $request = ServiceRequest::factory()->create(['pricing_mode' => $mode]);
    $request->forceFill(['state' => RequestState::OnTheWay])->save();

    expect(fn () => $request->transitionTo(RequestState::Diagnosing))->toThrow(InvalidTransition::class);

    $request->transitionTo(RequestState::InProgress);
    expect($request->fresh()?->state)->toBe(RequestState::InProgress);
})->with([PricingMode::Fixed, PricingMode::HourlyPackage]);

it('nos serviços por orçamento o técnico tem de diagnosticar antes de executar', function () {
    $request = ServiceRequest::factory()->create(['pricing_mode' => PricingMode::Quote]);
    $request->forceFill(['state' => RequestState::OnTheWay])->save();

    expect(fn () => $request->transitionTo(RequestState::InProgress))->toThrow(InvalidTransition::class);

    $request->transitionTo(RequestState::Diagnosing);
    $request->transitionTo(RequestState::QuoteSent);
    $request->transitionTo(RequestState::Diagnosing); // o cliente pede revisão
    $request->transitionTo(RequestState::QuoteSent);
    $request->transitionTo(RequestState::QuoteAccepted);
    $request->transitionTo(RequestState::InProgress);

    expect($request->transitions()->get()->map(fn ($t) => $t->to_state->value)->all())
        ->toBe(['created', 'diagnosing', 'quote_sent', 'diagnosing', 'quote_sent', 'quote_accepted', 'in_progress']);
});

it('o histórico regista quem mudou o estado e não pode ser alterado', function () {
    $client = User::factory()->create();
    $request = ServiceRequest::factory()->create(['client_id' => $client->id]);

    $this->actingAs($client);
    $request->transitionTo(RequestState::Searching);
    $request->by(null, 'timeout')->transitionTo(RequestState::NoProvider);

    $history = $request->transitions()->get();
    expect($history->map(fn ($t) => [$t->from_state?->value, $t->to_state->value, $t->actor_id, $t->reason])->all())->toBe([
        [null, 'created', $client->id, null],
        ['created', 'searching', $client->id, null],
        ['searching', 'no_provider', null, 'timeout'],
    ]);

    expect(fn () => $history->first()?->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $history->first()?->delete())->toThrow(LogicException::class);
});

it('deteta mudanças concorrentes (o estado em memória está desatualizado)', function () {
    $request = ServiceRequest::factory()->create();
    $request->transitionTo(RequestState::Searching);

    // Outro processo (ex.: o job de expiração) muda o estado primeiro
    $stale = ServiceRequest::query()->findOrFail($request->id);
    ServiceRequest::query()->findOrFail($request->id)->transitionTo(RequestState::NoProvider);

    expect(fn () => $stale->transitionTo(RequestState::Accepted))
        ->toThrow(InvalidTransition::class, 'O estado foi alterado entretanto');
});

it('uma oferta terminada não volta a mudar', function () {
    $request = ServiceRequest::factory()->create();
    $offer = RequestOffer::query()->create([
        'service_request_id' => $request->id, 'provider_profile_id' => ProviderProfile::factory()->create()->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 100, 'score_snapshot' => 50, 'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);

    $offer->transitionTo(OfferState::Viewed, ['viewed_at' => now()]);
    $offer->transitionTo(OfferState::Declined, ['responded_at' => now()]);

    expect(fn () => $offer->transitionTo(OfferState::Accepted))->toThrow(InvalidTransition::class);
});
