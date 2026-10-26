<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;

function pay(int $sessionId, string $key = 'pay-session-0001'): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/sessions/{$sessionId}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => $key]);
}

it('o aluno paga: o valor fica em escrow e a aula fica agendada', function () {
    $session = acceptedSession();
    apiLogin($session->student);

    pay($session->id)->assertOk()->assertJsonPath('data.state', 'scheduled');

    $ledger = app(LedgerService::class);
    expect($ledger->balance(Accounts::ESCROW))->toBe(25000)
        ->and($ledger->balance(Accounts::FUNDS_IN))->toBe(-25000)
        ->and(EscrowHold::query()->sole()->state)->toBe('held')
        ->and(SessionEvent::query()->where('type', 'payment_confirmed')->exists())->toBeTrue();
});

it('pagar duas vezes não cobra nem retém duas vezes (mesma chave ou chave nova)', function () {
    $session = acceptedSession();
    apiLogin($session->student);

    pay($session->id, 'pay-key-aaaa1')->assertOk();
    pay($session->id, 'pay-key-aaaa1')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    pay($session->id, 'pay-key-bbbb2')->assertOk();

    expect(PaymentCharge::query()->where('kind', 'charge')->count())->toBe(1)
        ->and(EscrowHold::query()->count())->toBe(1)
        ->and(LedgerTransaction::query()->where('type', 'escrow_hold')->count())->toBe(1)
        ->and(app(LedgerService::class)->balance(Accounts::ESCROW))->toBe(25000);
});

it('pagamento recusado: a aula passa a payment_failed e nada entra no ledger', function () {
    $session = acceptedSession();
    $session->student->forceFill(['phone' => '+244923450000'])->save(); // o mock recusa telefones terminados em 0000
    apiLogin($session->student);

    pay($session->id)->assertStatus(402)->assertJsonPath('error.code', 'payment_failed');

    expect($session->fresh()?->state)->toBe(SessionState::PaymentFailed)
        ->and(LedgerTransaction::query()->count())->toBe(0);
});

it('fora da janela de pagamento: recusa e a regra R3 liberta o formador', function () {
    $session = acceptedSession();

    $this->travel(11)->minutes();
    apiLogin($session->student); // PIN confirmado agora (o anterior teria expirado com a viagem)
    pay($session->id)->assertStatus(410)->assertJsonPath('error.code', 'payment_window_expired');

    $this->artisan('longa:sessions:tick')->assertSuccessful();

    expect($session->fresh()?->state)->toBe(SessionState::PaymentFailed)
        ->and(SessionEvent::query()->where('type', 'payment_window_expired')->exists())->toBeTrue();
});

it('só o aluno da aula paga e aulas em dinheiro não se pagam aqui', function () {
    $session = acceptedSession();
    apiLogin(User::factory()->create());
    pay($session->id)->assertForbidden();

    $cash = acceptedSession('cash');
    apiLogin($cash->student);
    pay($cash->id, 'pay-cash-00001')->assertStatus(409)->assertJsonPath('error.code', 'cash_session');
});

it('exige a Idempotency-Key', function () {
    $session = acceptedSession();
    apiLogin($session->student);

    $this->postJson("/api/v1/sessions/{$session->id}/pay", ['method' => 'multicaixa_express'])->assertStatus(400);
});
