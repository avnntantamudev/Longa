<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\TrainerProfile;
use App\Support\StateMachine\InvalidTransition;

/*
| Matriz completa das transições: cada par (de, para) é permitido ou não exatamente como em
| docs/state-machines.md.
*/

$requestAllowed = [
    'draft' => ['searching', 'cancelled'],
    'searching' => ['matched', 'expired', 'cancelled'],
    'matched' => [],
    'expired' => [],
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
    $request = TrainingRequest::factory()->create();

    $request->transitionTo(RequestState::Searching, ['expires_at' => now()->addMinutes(5)]);

    expect($request->state)->toBe(RequestState::Searching)
        ->and($request->fresh()?->state)->toBe(RequestState::Searching)
        ->and($request->fresh()?->expires_at)->not->toBeNull();
});

it('recusa transições inválidas sem alterar nada', function (RequestState $from, RequestState $to) {
    $request = TrainingRequest::factory()->create();
    $request->forceFill(['state' => $from])->save();

    expect(fn () => $request->transitionTo($to))->toThrow(InvalidTransition::class);
    expect($request->fresh()?->state)->toBe($from);
})->with([
    'matched → searching' => [RequestState::Matched, RequestState::Searching],
    'expired → matched' => [RequestState::Expired, RequestState::Matched],
    'cancelled → searching' => [RequestState::Cancelled, RequestState::Searching],
    'draft → matched' => [RequestState::Draft, RequestState::Matched],
]);

it('deteta mudanças concorrentes (o estado em memória está desatualizado)', function () {
    $request = TrainingRequest::factory()->create();
    $request->transitionTo(RequestState::Searching);

    // Outro processo (ex.: o job de expiração) muda o estado primeiro
    $stale = TrainingRequest::query()->findOrFail($request->id);
    TrainingRequest::query()->findOrFail($request->id)->transitionTo(RequestState::Expired);

    expect(fn () => $stale->transitionTo(RequestState::Matched))
        ->toThrow(InvalidTransition::class, 'O estado foi alterado entretanto');
});

it('uma oferta terminada não volta a mudar', function () {
    $request = TrainingRequest::factory()->create();
    $offer = RequestOffer::query()->create([
        'training_request_id' => $request->id, 'trainer_profile_id' => TrainerProfile::factory()->create()->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 100, 'score_snapshot' => 50, 'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);

    $offer->transitionTo(OfferState::Viewed, ['viewed_at' => now()]);
    $offer->transitionTo(OfferState::Declined, ['responded_at' => now()]);

    expect(fn () => $offer->transitionTo(OfferState::Accepted))->toThrow(InvalidTransition::class);
});
