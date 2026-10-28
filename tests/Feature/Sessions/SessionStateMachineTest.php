<?php

declare(strict_types=1);

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\ProviderProfile;
use App\Support\StateMachine\InvalidTransition;

/*
| Matriz completa da máquina de estados da aula (docs/state-machines.md).
*/

$allowed = [
    'pending_payment' => ['scheduled', 'payment_failed'],
    'payment_failed' => [],
    'scheduled' => ['in_progress', 'cancelled_by_client', 'cancelled_by_provider', 'no_show_provider', 'no_show_client', 'awaiting_confirmation'],
    'in_progress' => ['awaiting_confirmation'],
    'awaiting_confirmation' => ['completed', 'disputed', 'refunded'],
    'completed' => [],
    'cancelled_by_client' => [],
    'cancelled_by_provider' => [],
    'no_show_provider' => ['disputed'],
    'no_show_client' => ['disputed'],
    'disputed' => ['completed', 'refunded', 'partially_refunded'],
    'refunded' => [],
    'partially_refunded' => [],
];

function makeSession(SessionState $state = SessionState::Scheduled): ServiceJob
{
    $request = ServiceRequest::factory()->create();
    $session = ServiceJob::query()->create([
        'service_request_id' => $request->id, 'client_id' => $request->client_id,
        'provider_profile_id' => ProviderProfile::factory()->create()->id, 'category_id' => $request->category_id,
        'title' => 'Aula', 'amount' => 25000, 'commission' => 3750, 'provider_net' => 21250,
        'scheduled_at' => now()->addDay(), 'duration_minutes' => 90, 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => -8.9167, 'longitude' => 13.1833,
    ]);
    $session->forceFill(['state' => $state])->save();

    return $session;
}

it('define exatamente as transições documentadas', function () use ($allowed) {
    expect(array_map(fn (SessionState $s) => $s->value, SessionState::cases()))->toEqualCanonicalizing(array_keys($allowed));

    foreach (SessionState::cases() as $from) {
        foreach (SessionState::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} → {$to->value}");
        }
    }
});

it('cada transição grava um evento na linha do tempo', function () {
    $session = makeSession();

    app(SessionLifecycle::class)->transition($session, SessionState::InProgress, 'started', $session->client, ['via' => 'teste'], ['started_at' => now()]);

    $event = SessionEvent::query()->sole();
    expect($session->fresh()?->state)->toBe(SessionState::InProgress)
        ->and($session->fresh()?->started_at)->not->toBeNull()
        ->and($event->from_state)->toBe('scheduled')
        ->and($event->to_state)->toBe('in_progress')
        ->and($event->metadata)->toBe(['via' => 'teste']);
});

it('transições inválidas não mudam o estado nem gravam eventos', function (SessionState $from, SessionState $to) {
    $session = makeSession($from);

    expect(fn () => app(SessionLifecycle::class)->transition($session, $to, 'x'))->toThrow(InvalidTransition::class);
    expect($session->fresh()?->state)->toBe($from)->and(SessionEvent::query()->count())->toBe(0);
})->with([
    'concluída → em disputa' => [SessionState::Completed, SessionState::Disputed],
    'agendada → concluída sem confirmação' => [SessionState::Scheduled, SessionState::Completed],
    'em curso → cancelada' => [SessionState::InProgress, SessionState::CancelledByClient],
    'pagamento pendente → em curso' => [SessionState::PendingPayment, SessionState::InProgress],
    'reembolsada → concluída' => [SessionState::Refunded, SessionState::Completed],
]);

it('a linha do tempo não aceita alterações', function () {
    $session = makeSession();
    $event = app(SessionLifecycle::class)->record($session, 'qr_issued');

    expect(fn () => $event->update(['type' => 'x']))->toThrow(LogicException::class);
});

it('calcula o fim previsto a partir da duração', function () {
    $session = makeSession();

    expect($session->ends_at?->equalTo($session->scheduled_at->copy()->addMinutes(90)))->toBeTrue();
});
