<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\LedgerDiscrepancyNotification;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Sessions\Actions\CancelSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerAccountBalance;
use App\Modules\Wallet\Models\LedgerReconciliation;
use App\Modules\Wallet\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('um ledger saudável passa e fica registado', function () {
    paidSession();
    $other = paidSession();
    app(CancelSession::class)->handle($other, $other->student, null);

    $this->artisan('longa:ledger:reconcile')->assertSuccessful();

    $report = LedgerReconciliation::query()->sole();
    expect($report->status)->toBe('ok')
        ->and($report->issues)->toBe([])
        ->and($report->totals['escrow'])->toBe(25000);
    Notification::assertSentTimes(LedgerDiscrepancyNotification::class, 0);
});

it('cache de saldo adulterada: alerta crítico, admins avisados e rebuild corrige', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    paidSession();
    Log::spy();

    $escrowId = LedgerAccount::query()->where('code', Accounts::ESCROW)->value('id');
    LedgerAccountBalance::query()->whereKey($escrowId)->update(['balance' => 999]);

    $this->artisan('longa:ledger:reconcile')->assertFailed();

    $report = LedgerReconciliation::query()->latest('id')->first();
    expect($report?->status)->toBe('discrepancy')
        ->and(collect($report?->issues)->pluck('check')->all())->toContain('balance_cache_mismatch');
    Notification::assertSentTo($admin, LedgerDiscrepancyNotification::class);
    Log::shouldHaveReceived('critical')->withArgs(fn ($message) => $message === 'ledger.reconciliation_failed')->once();

    $this->artisan('longa:ledger:rebuild')->expectsOutputToContain('Contas corrigidas: 1')->assertSuccessful();
    $this->artisan('longa:ledger:reconcile')->assertSuccessful();
});

it('lançamento desequilibrado inserido por fora do serviço é detetado', function () {
    paidSession();
    $tx = LedgerTransaction::query()->firstOrFail();
    $account = LedgerAccount::query()->where('code', Accounts::PLATFORM_REVENUE)->firstOr(fn () => LedgerAccount::query()->create(['code' => Accounts::PLATFORM_REVENUE, 'type' => 'platform_revenue']));

    // Os triggers impedem UPDATE/DELETE, mas um INSERT direto na BD só é apanhado pela reconciliação
    DB::table('ledger_entries')->insert(['ledger_transaction_id' => $tx->id, 'ledger_account_id' => $account->id, 'amount' => 500]);

    $checks = collect(app(App\Modules\Wallet\Services\LedgerReconciler::class)->run()->issues)->pluck('check')->all();

    expect($checks)->toContain('transaction_unbalanced', 'global_sum', 'balance_cache_mismatch');
});

it('escrow diferente das retenções ativas é detetado', function () {
    paidSession();
    EscrowHold::query()->update(['amount' => 20000]);

    $report = app(App\Modules\Wallet\Services\LedgerReconciler::class)->run();

    expect($report->status)->toBe('discrepancy')
        ->and(collect($report->issues)->firstWhere('check', 'escrow_mismatch')['data'])->toBe(['ledger' => 25000, 'holds' => 20000]);
});

it('retenção sem destino depois do prazo máximo gera aviso', function () {
    $session = paidSession();
    $this->travelTo($session->ends_at?->copy()->addDays(8));

    $report = app(App\Modules\Wallet\Services\LedgerReconciler::class)->run();

    expect($report->status)->toBe('ok')
        ->and(collect($report->issues)->pluck('check')->all())->toBe(['holds_overdue']);
    Notification::assertSentTimes(LedgerDiscrepancyNotification::class, 0);
});

it('reembolso decidido e não executado no gateway gera aviso', function () {
    Illuminate\Support\Facades\Queue::fake(); // o job ExecuteRefund não corre
    $session = paidSession();
    app(CancelSession::class)->handle($session, $session->student, null);

    $this->travel(2)->hours();
    $report = app(App\Modules\Wallet\Services\LedgerReconciler::class)->run();

    expect($report->status)->toBe('ok')
        ->and(collect($report->issues)->pluck('check')->all())->toBe(['refunds_pending'])
        ->and($report->totals['refunds_payable'])->toBe(25000);
});
