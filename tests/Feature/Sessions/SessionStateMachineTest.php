<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Support\StateMachine\InvalidTransition;

/*
| Execução do pedido (o serviço é o próprio pedido): cada transição fica no histórico de estados (quem, evento) e
| na linha do tempo (detalhes). A matriz completa das transições está em tests/Feature/Requests/StateMachineTest.php.
*/

function makeSession(RequestState $state = RequestState::Accepted): ServiceRequest
{
    $session = acceptedSession('cash', ['duration_minutes' => 90]);
    $session->forceFill(['state' => $state])->save();

    return $session;
}

it('cada transição grava o histórico do pedido e um evento na linha do tempo', function () {
    $session = makeSession(RequestState::OnTheWay);

    app(SessionLifecycle::class)->transition($session, RequestState::InProgress, 'started', $session->provider->user, ['via' => 'teste'], ['started_at' => now()]);

    $event = SessionEvent::query()->where('service_request_id', $session->id)->sole();
    $transition = $session->transitions()->get()->last();
    expect($session->fresh()?->state)->toBe(RequestState::InProgress)
        ->and($session->fresh()?->started_at)->not->toBeNull()
        ->and([$event->from_state, $event->to_state, $event->metadata])->toBe(['on_the_way', 'in_progress', ['via' => 'teste']])
        ->and([$transition?->to_state, $transition?->actor_id, $transition?->reason])->toBe([RequestState::InProgress, $session->provider->user_id, 'started']);
});

it('transições inválidas não mudam o estado nem gravam eventos', function (RequestState $from, RequestState $to) {
    $session = makeSession($from);
    $history = $session->transitions()->count();

    expect(fn () => app(SessionLifecycle::class)->transition($session, $to, 'x'))->toThrow(InvalidTransition::class);
    expect($session->fresh()?->state)->toBe($from)
        ->and(SessionEvent::query()->where('service_request_id', $session->id)->count())->toBe(0)
        ->and($session->transitions()->count())->toBe($history);
})->with([
    'pago → em disputa' => [RequestState::Paid, RequestState::Disputed],
    'aceite → concluído sem execução' => [RequestState::Accepted, RequestState::Completed],
    'em execução → cancelado' => [RequestState::InProgress, RequestState::Cancelled],
    'aceite → em execução sem ir a caminho' => [RequestState::Accepted, RequestState::InProgress],
    'cancelado → pago' => [RequestState::Cancelled, RequestState::Paid],
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
