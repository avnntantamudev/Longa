<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;

/**
 * Leva a aula até "concluída por QR" com as Actions (sem HTTP).
 */
function completeByQr(TrainingSession $session): TrainingSession
{
    test()->travelTo($session->scheduled_at->copy()->addMinutes(5));
    app(StartSession::class)->handle($session, $session->trainer->user);
    $token = app(FinishSession::class)->handle($session, $session->trainer->user)['qr']['tokens'][0]['token'];

    return app(ConfirmSession::class)->handle($session, $session->student, $token, null);
}

it('aula em dinheiro: o QR conclui sem mexer no ledger; o aluno confirma e fica a comissão em dívida', function () {
    $session = completeByQr(acceptedSession('cash'));
    $ledger = app(LedgerService::class);

    expect(LedgerTransaction::query()->count())->toBe(0);

    apiLogin($session->student);
    $this->postJson("/api/v1/sessions/{$session->id}/confirm-cash", [], ['Idempotency-Key' => 'cash-confirm-1'])->assertOk();
    $this->postJson("/api/v1/sessions/{$session->id}/confirm-cash", [], ['Idempotency-Key' => 'cash-confirm-2'])->assertOk();

    expect($ledger->balance(Accounts::trainerCommissionDue($session->trainer_profile_id)))->toBe(-3750)
        ->and($ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(3750)
        ->and(LedgerTransaction::query()->where('type', 'cash_commission_due')->count())->toBe(1);
});

it('não se confirma o pagamento em dinheiro antes de a aula estar concluída', function () {
    $session = acceptedSession('cash');
    apiLogin($session->student);

    $this->postJson("/api/v1/sessions/{$session->id}/confirm-cash", [], ['Idempotency-Key' => 'cash-early-01'])
        ->assertStatus(409)->assertJsonPath('error.code', 'session_not_completed');
});

it('a dívida é descontada automaticamente quando o formador recebe uma aula eletrónica', function () {
    $cash = completeByQr(acceptedSession('cash'));
    app(ConfirmCashPayment::class)->handle($cash, $cash->student);

    // Aula eletrónica do mesmo formador
    $electronic = paidSession(['trainer_profile_id' => $cash->trainer_profile_id, 'scheduled_at' => now()->addDays(2)]);
    completeByQr($electronic);

    $ledger = app(LedgerService::class);
    expect($ledger->balance(Accounts::trainerCommissionDue($cash->trainer_profile_id)))->toBe(0)
        ->and($ledger->balance(Accounts::trainerAvailable($cash->trainer_profile_id)))->toBe(21250 - 3750)
        ->and(LedgerTransaction::query()->where('type', 'commission_offset')->count())->toBe(1);
});

it('o admin regista a comissão recebida em dinheiro, sem ultrapassar a dívida', function () {
    $session = completeByQr(acceptedSession('cash'));
    app(ConfirmCashPayment::class)->handle($session, $session->student);
    $url = "/api/v1/admin/trainers/{$session->trainer_profile_id}/commission-payments";

    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->postJson($url, ['amount' => 5000], ['Idempotency-Key' => 'receive-too-much'])->assertStatus(422);
    $this->postJson($url, ['amount' => 3750, 'note' => 'Entregue em mão'], ['Idempotency-Key' => 'receive-0001'])
        ->assertCreated()->assertJsonPath('data.outstanding', 0);
    $this->postJson($url, ['amount' => 3750, 'note' => 'Entregue em mão'], ['Idempotency-Key' => 'receive-0001'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(app(LedgerService::class)->balance(Accounts::CASH_SETTLEMENT))->toBe(-3750)
        ->and(LedgerTransaction::query()->where('type', 'cash_commission_received')->count())->toBe(1);

    apiLogin($session->trainer->user);
    $this->postJson($url, ['amount' => 1], ['Idempotency-Key' => 'receive-hack'])->assertForbidden();
});

it('aula em mão de valor tão baixo que a comissão é 0 Kz confirma sem dívida', function () {
    $session = acceptedSession('cash', ['amount' => 3, 'commission' => 0, 'trainer_net' => 3]);
    completeByQr($session);

    app(ConfirmCashPayment::class)->handle($session->fresh() ?? $session, $session->student);

    expect($session->fresh()?->cash_confirmed_at)->not->toBeNull()
        ->and(app(App\Modules\Payments\Services\CommissionDebts::class)->outstanding($session->trainer_profile_id))->toBe(0)
        ->and(LedgerTransaction::query()->where('type', 'cash_commission_due')->count())->toBe(0);
});
