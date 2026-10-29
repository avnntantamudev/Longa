<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use Illuminate\Support\Facades\Notification;

/*
| BISCA, Fase 2.6: o técnico marca a caminho, em execução e concluído; o cliente confirma. Sem resposta do cliente
| no prazo (48 h) e sem disputa, a confirmação é automática.
*/

beforeEach(function () {
    Notification::fake();
});

function execute(string $path, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->postJson($path, $body, ['Idempotency-Key' => 'exec-'.uniqid()]);
}

it('o técnico marca a caminho (o cliente é avisado), em execução e concluído; o cliente confirma', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->subMinutes(30));

    // Só o técnico do serviço
    apiLogin($session->client);
    execute("/api/v1/sessions/{$session->id}/on-the-way")->assertForbidden();

    apiLogin($session->provider->user);
    execute("/api/v1/sessions/{$session->id}/on-the-way")->assertOk()->assertJsonPath('data.state', 'on_the_way');
    execute("/api/v1/sessions/{$session->id}/on-the-way")->assertOk(); // repetir não faz nada
    Notification::assertSentToTimes($session->client, SessionUpdateNotification::class, 1);
    Notification::assertSentTo($session->client, SessionUpdateNotification::class, fn ($n) => $n->kind === SessionUpdateNotification::ON_THE_WAY);

    $this->travelTo($session->scheduled_at);
    execute("/api/v1/sessions/{$session->id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');
    execute("/api/v1/sessions/{$session->id}/finish")->assertOk();
    expect($session->fresh()?->state)->toBe(RequestState::Completed);

    apiLogin($session->client);
    execute("/api/v1/sessions/{$session->id}/confirm-without-qr")->assertOk()->assertJsonPath('data.state', 'paid');

    expect($session->transitions()->get()->map(fn ($t) => $t->to_state->value)->all())
        ->toBe(['created', 'on_the_way', 'in_progress', 'completed', 'paid'])
        ->and(balances($session)['escrow'])->toBe(0);
});

it('não marca a caminho com o pagamento por fazer', function () {
    $session = acceptedSession('electronic');
    apiLogin($session->provider->user);

    execute("/api/v1/sessions/{$session->id}/on-the-way")->assertStatus(409)->assertJsonPath('error.code', 'payment_pending');
});

it('sem resposta do cliente em 48 h e sem disputa, a confirmação é automática e o técnico recebe', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session->fresh() ?? $session, $session->provider->user);

    $this->travel(47)->hours();
    $this->artisan('longa:sessions:tick');
    expect($session->fresh()?->state)->toBe(RequestState::Completed);

    $this->travel(2)->hours();
    $this->artisan('longa:sessions:tick');

    expect($session->fresh()?->state)->toBe(RequestState::Paid)
        ->and($session->transitions()->get()->last()?->actor_id)->toBeNull()
        ->and(balances($session)['provider'])->toBe($session->provider_net);
});

it('com disputa aberta no prazo, não há confirmação automática', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session->fresh() ?? $session, $session->provider->user);

    apiLogin($session->client);
    $this->post("/api/v1/sessions/{$session->id}/disputes", [
        'reason' => 'lesson_not_happened', 'description' => 'O trabalho ficou por acabar.',
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'dispute-exec-1'])->assertCreated();

    $this->travel(49)->hours();
    $this->artisan('longa:sessions:tick');

    expect($session->fresh()?->state)->toBe(RequestState::Disputed)
        ->and(balances($session)['escrow'])->toBe(25000);
});
