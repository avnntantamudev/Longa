<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\AddendumFlow;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Sessions\Actions\ConfirmWithoutQr;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Modules\Wallet\Models\Withdrawal;
use App\Modules\Wallet\Services\LedgerReconciler;
use App\Modules\Wallet\Services\WalletOperations;
use Illuminate\Support\Facades\Notification;

/*
| BISCA, Fase 3 — critérios de aceitação: o saldo é sempre a soma dos movimentos; uma operação falhada a meio não
| deixa movimentos parciais; fluxo completo em dinheiro e em digital (reserva, libertação, comissão e disputa com
| reembolso); auditoria das ações sensíveis.
*/

beforeEach(function () {
    Notification::fake();
    $this->ledger = app(LedgerService::class);
});

function runToCompleted(ServiceRequest $session): ServiceRequest
{
    $provider = $session->provider->user;
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $provider);
    app(FinishSession::class)->handle($session->fresh() ?? $session, $provider);

    return $session->fresh() ?? $session;
}

/**
 * Cada conta: saldo em cache = soma dos movimentos; tudo soma zero; a reconciliação não encontra diferenças.
 */
function assertBooksBalance(): void
{
    $ledger = app(LedgerService::class);
    foreach (LedgerAccount::query()->pluck('code') as $code) {
        expect($ledger->balance($code))->toBe($ledger->balanceFromEntries($code), $code);
    }
    expect((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and(app(LedgerReconciler::class)->run()->isOk())->toBeTrue();
}

it('digital: reserva no escrow, libertação na confirmação, 80% para o técnico e 20% de comissão', function () {
    $session = acceptedSession('electronic', ['commission' => 5000, 'provider_net' => 20000, 'commission_percent' => 20]);
    app(PayForSession::class)->handle($session, $session->client, 'multicaixa_express');
    expect($this->ledger->balance(Accounts::ESCROW))->toBe(25000)
        ->and($this->ledger->balance(Accounts::providerAvailable($session->provider_profile_id)))->toBe(0);

    $session = runToCompleted($session->fresh() ?? $session);
    app(ConfirmWithoutQr::class)->handle($session, $session->client);

    expect($session->fresh()?->state)->toBe(RequestState::Paid)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and($this->ledger->balance(Accounts::providerAvailable($session->provider_profile_id)))->toBe(20000)
        ->and($this->ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(5000);
    assertBooksBalance();
});

it('dinheiro: o técnico recebe em mão e os 20% são debitados da carteira dele', function () {
    $session = acceptedSession('cash', ['commission' => 5000, 'provider_net' => 20000, 'commission_percent' => 20]);
    $provider = $session->provider;
    $session = runToCompleted($session);
    app(ConfirmWithoutQr::class)->handle($session, $session->client);
    app(ConfirmCashPayment::class)->handle($session->fresh() ?? $session, $session->client);

    // Sem saldo: fica em dívida. Nada passou pelo escrow.
    expect($this->ledger->balance(Accounts::providerCommissionDue($provider)))->toBe(-5000)
        ->and($this->ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(5000)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and(EscrowHold::query()->count())->toBe(0);

    // A recarga paga a dívida: fica debitada da carteira
    app(WalletOperations::class)->topUp($provider, 8000, 'multicaixa_express', 'accept-cash-1');
    expect($this->ledger->balance(Accounts::providerCommissionDue($provider)))->toBe(0)
        ->and($this->ledger->balance(Accounts::providerAvailable($provider)))->toBe(3000);
    assertBooksBalance();
});

it('disputa com reembolso: o dinheiro retido volta ao cliente e o técnico não recebe nem há comissão', function () {
    $session = paidSession();
    $session = runToCompleted($session);
    app(OpenDispute::class)->handle($session, $session->client, 'lesson_not_happened', 'O técnico não fez o serviço combinado.');
    expect($session->fresh()?->state)->toBe(RequestState::Disputed)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(25000);

    $admin = User::factory()->withRole(Role::Admin)->create();
    app(ResolveDispute::class)->handle(Dispute::query()->sole(), $admin, ResolveDispute::REFUND, null, 'Evidências a favor do cliente.');

    expect($session->fresh()?->state)->toBe(RequestState::Cancelled)
        ->and($session->fresh()?->closed_reason)->toBe('refunded')
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and($this->ledger->balance(Accounts::providerAvailable($session->provider_profile_id)))->toBe(0)
        ->and($this->ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(0)
        ->and(EscrowHold::query()->sole()->state)->toBe(EscrowHold::REFUNDED);
    assertBooksBalance();
});

it('o saldo é sempre a soma dos movimentos, depois de todos os tipos de operação', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    $wallet = app(WalletOperations::class);
    $admin = User::factory()->withRole(Role::Admin)->create();

    $wallet->topUp($provider, 40000, 'card', 'sum-1');
    $withdrawal = $wallet->requestWithdrawal($provider, 10000, '923456789');
    $wallet->payWithdrawal($withdrawal, $admin, 'TRF-1');
    $rejected = $wallet->requestWithdrawal($provider, 6000, '923456789');
    $wallet->rejectWithdrawal($rejected, $admin, 'Dados errados');
    $wallet->adjust($provider, -500, 'Correção', $admin, 'sum-adj');

    $session = paidSession(['provider_profile_id' => $provider->id]);
    $session = runToCompleted($session);
    app(ConfirmWithoutQr::class)->handle($session, $session->client);

    expect($this->ledger->balance(Accounts::providerAvailable($provider)))
        ->toBe((int) LedgerEntry::query()->whereHas('account', fn ($q) => $q->where('code', Accounts::providerAvailable($provider)))->sum('amount'));
    assertBooksBalance();
});

it('um movimento que não fecha é recusado inteiro', function () {
    $before = LedgerEntry::query()->count();

    expect(fn () => $this->ledger->post('adjustment', 'bad:1', [Accounts::ESCROW => 100, Accounts::FUNDS_IN => -50]))->toThrow(LedgerException::class);

    expect(LedgerEntry::query()->count())->toBe($before)
        ->and(LedgerTransaction::query()->where('idempotency_key', 'bad:1')->exists())->toBeFalse();
});

it('levantamento que falha a meio: nem pedido nem movimentos', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    app(WalletOperations::class)->topUp($provider, 20000, 'multicaixa_express', 'atomic-1');
    $entries = LedgerEntry::query()->count();

    $failing = Mockery::mock(LedgerService::class)->makePartial();
    $failing->shouldReceive('post')->andThrow(new RuntimeException('Falha simulada a meio da operação'));
    app()->instance(LedgerService::class, $failing);

    expect(fn () => app(WalletOperations::class)->requestWithdrawal($provider, 10000, '923456789'))->toThrow(RuntimeException::class);
    app()->forgetInstance(LedgerService::class);

    expect(Withdrawal::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe($entries)
        ->and($this->ledger->balance(Accounts::providerAvailable($provider)))->toBe(20000);
});

it('confirmação que falha ao libertar o dinheiro: o serviço continua por confirmar e nada se move', function () {
    $session = runToCompleted(paidSession());
    $entries = LedgerEntry::query()->count();

    $failing = Mockery::mock(SessionSettlement::class);
    $failing->shouldReceive('complete')->andThrow(new RuntimeException('Falha simulada ao libertar'));
    app()->instance(SessionSettlement::class, $failing);

    expect(fn () => app(ConfirmWithoutQr::class)->handle($session, $session->client))->toThrow(RuntimeException::class);
    app()->forgetInstance(SessionSettlement::class);

    expect($session->fresh()?->state)->toBe(RequestState::Completed)
        ->and($session->fresh()?->confirmed_at)->toBeNull()
        ->and(LedgerEntry::query()->count())->toBe($entries)
        ->and($session->transitions()->get()->last()?->to_state)->toBe(RequestState::Completed);
});

it('decisão de disputa que falha ao mover o dinheiro: a disputa continua aberta e nada se move', function () {
    $session = runToCompleted(paidSession());
    app(OpenDispute::class)->handle($session, $session->client, 'lesson_not_happened', 'O técnico não fez o serviço combinado.');
    $entries = LedgerEntry::query()->count();

    $failing = Mockery::mock(SessionSettlement::class)->makePartial();
    $failing->shouldReceive('heldAmount')->andReturn(25000);
    $failing->shouldReceive('split')->andThrow(new RuntimeException('Falha simulada ao reembolsar'));
    app()->instance(SessionSettlement::class, $failing);

    $admin = User::factory()->withRole(Role::Admin)->create();
    expect(fn () => app(ResolveDispute::class)->handle(Dispute::query()->sole(), $admin, ResolveDispute::REFUND, null, 'Reembolso.'))->toThrow(RuntimeException::class);
    app()->forgetInstance(SessionSettlement::class);

    expect($session->fresh()?->state)->toBe(RequestState::Disputed)
        ->and(Dispute::query()->sole()->status)->toBe(Dispute::OPEN)
        ->and(LedgerEntry::query()->count())->toBe($entries);
});

it('as ações sensíveis ficam na auditoria', function () {
    $ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
    $session = acceptedSession('electronic', ['category_id' => $ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'commission' => 0, 'provider_net' => 0, 'payment_status' => null, 'payment_due_at' => null, 'visit_fee' => 5000])->save();
    $provider = $session->provider->user;
    $admin = User::factory()->withRole(Role::Admin)->create();

    $flow = app(QuoteFlow::class);
    $flow->startDiagnosis($session, $provider);
    $quote = $flow->submit($session, $provider, ['labour_amount' => 40000, 'execution_days' => 1]);
    $flow->accept($quote, $session->client);
    app(PayForSession::class)->handle($session->fresh() ?? $session, $session->client, 'multicaixa_express');
    app(StartSession::class)->handle($session->fresh() ?? $session, $provider);
    $addendum = app(AddendumFlow::class)->submit($session, $provider, 'Trocar o motor do ventilador', 20000);
    app(AddendumFlow::class)->clientApprove($addendum, $session->client);
    app(AddendumFlow::class)->teamReview($addendum, $admin, true, 'Peça confirmada');
    app(AddendumFlow::class)->pay($addendum->fresh() ?? $addendum, $session->client, 'card');
    app(FinishSession::class)->handle($session->fresh() ?? $session, $provider);
    app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->client, 'lesson_not_happened', 'O ventilador continua avariado.');
    app(ResolveDispute::class)->handle(Dispute::query()->sole(), $admin, ResolveDispute::RELEASE, null, 'Serviço feito.');

    $wallet = app(WalletOperations::class);
    $wallet->topUp($session->provider, 5000, 'reference', 'audit-1');
    $wallet->payWithdrawal($wallet->requestWithdrawal($session->provider, 5000, '923456789'), $admin, 'TRF-2');
    $wallet->adjust($session->provider, 100, 'Acerto', $admin, 'audit-adj');

    $actions = AuditLog::query()->pluck('action')->unique()->all();
    expect($actions)->toContain(
        'payments.visit_fee_commission', 'quotes.sent', 'quotes.accepted', 'payments.charged', 'escrow.held',
        'addenda.submitted', 'addenda.client_approved', 'addenda.team_approved', 'addenda.paid', 'escrow.topped_up',
        'disputes.opened', 'disputes.resolved', 'escrow.released',
        'wallet.topped_up', 'wallet.withdrawal_requested', 'wallet.withdrawal_paid', 'wallet.adjusted',
    );
    assertBooksBalance();
});
