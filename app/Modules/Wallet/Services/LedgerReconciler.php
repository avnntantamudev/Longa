<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Services;

use App\Modules\Notifications\Notifications\LedgerDiscrepancyNotification;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Models\LedgerAccountBalance;
use App\Modules\Wallet\Models\LedgerReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Verifica a integridade do dinheiro. Não corrige nada: regista o resultado e, se houver diferenças,
 * escreve um log crítico e avisa os administradores.
 *
 * Verificações:
 *  1. cada transação soma 0 e bate com os totais declarados;
 *  2. a soma de todos os lançamentos é 0;
 *  3. o saldo de escrow = soma das retenções ativas (held/disputed);
 *  4. a cache de saldos = soma dos lançamentos de cada conta;
 *  5. nenhuma conta de formador com saldo disponível negativo;
 *  6. retenções sem destino depois do prazo máximo (aviso);
 *  7. reembolsos decididos há mais de 1 h e ainda não executados no gateway (aviso).
 */
class LedgerReconciler
{
    public function run(): LedgerReconciliation
    {
        $started = hrtime(true);
        $issues = [];

        foreach ($this->unbalancedTransactions() as $row) {
            $issues[] = $this->issue('transaction_unbalanced', 'critical', "Transação {$row->id} não está equilibrada.", (array) $row);
        }

        $globalSum = (int) DB::table('ledger_entries')->sum('amount');
        if ($globalSum !== 0) {
            $issues[] = $this->issue('global_sum', 'critical', "A soma de todos os lançamentos é {$globalSum} Kz (devia ser 0).", ['sum' => $globalSum]);
        }

        $escrowLedger = $this->entriesBalance(Accounts::ESCROW);
        $escrowHolds = (int) EscrowHold::query()->whereIn('state', EscrowHold::ACTIVE)->sum('amount');
        if ($escrowLedger !== $escrowHolds) {
            $issues[] = $this->issue('escrow_mismatch', 'critical', "Escrow no ledger ({$escrowLedger} Kz) difere das retenções ativas ({$escrowHolds} Kz).", ['ledger' => $escrowLedger, 'holds' => $escrowHolds]);
        }

        foreach ($this->cacheMismatches() as $row) {
            $issues[] = $this->issue('balance_cache_mismatch', 'critical', "Cache de saldo da conta {$row->code} ({$row->cached} Kz) difere dos lançamentos ({$row->actual} Kz).", (array) $row);
        }

        foreach ($this->negativeProviderBalances() as $row) {
            $issues[] = $this->issue('negative_provider_balance', 'critical', "Conta {$row->code} com saldo negativo ({$row->balance} Kz).", (array) $row);
        }

        $overdue = EscrowHold::query()->whereIn('state', EscrowHold::ACTIVE)->where('expires_at', '<', now())->pluck('service_job_id');
        if ($overdue->isNotEmpty()) {
            $issues[] = $this->issue('holds_overdue', 'warning', $overdue->count().' retenção(ões) sem destino depois do prazo máximo.', ['session_ids' => $overdue->all()]);
        }

        $pendingRefunds = $this->pendingRefunds();
        if ($pendingRefunds !== []) {
            $issues[] = $this->issue('refunds_pending', 'warning', count($pendingRefunds).' reembolso(s) por executar no gateway há mais de 1 h.', ['hold_ids' => $pendingRefunds]);
        }

        $report = LedgerReconciliation::query()->create([
            'status' => collect($issues)->contains('severity', 'critical') ? LedgerReconciliation::DISCREPANCY : LedgerReconciliation::OK,
            'issues' => $issues,
            'totals' => [
                'escrow' => $escrowLedger,
                'platform_revenue' => $this->entriesBalance(Accounts::PLATFORM_REVENUE),
                'refunds_payable' => $this->entriesBalance(Accounts::REFUNDS_PAYABLE),
                'funds_in' => $this->entriesBalance(Accounts::FUNDS_IN),
                'transactions' => DB::table('ledger_transactions')->count(),
            ],
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'checked_at' => now(),
        ]);

        if (! $report->isOk()) {
            Log::critical('ledger.reconciliation_failed', ['reconciliation_id' => $report->id, 'issues' => $issues]);
            Notification::send(User::role(Role::Admin->value)->get(), new LedgerDiscrepancyNotification($report));
        } elseif ($issues !== []) {
            Log::warning('ledger.reconciliation_warnings', ['reconciliation_id' => $report->id, 'issues' => $issues]);
        }

        return $report;
    }

    /**
     * Reconstrói a cache de saldos a partir dos lançamentos (contas bloqueadas por ordem de id).
     *
     * @return int número de contas corrigidas
     */
    public function rebuildBalances(): int
    {
        return DB::transaction(function () {
            $balances = LedgerAccountBalance::query()->orderBy('ledger_account_id')->lockForUpdate()->get();
            $actual = DB::table('ledger_entries')
                ->selectRaw('ledger_account_id, SUM(amount) AS total, MAX(id) AS last_id')
                ->groupBy('ledger_account_id')
                ->get()
                ->keyBy('ledger_account_id');

            $fixed = 0;
            foreach ($balances as $balance) {
                $row = $actual->get($balance->ledger_account_id);
                $total = (int) ($row->total ?? 0);
                if ($balance->balance !== $total) {
                    $balance->forceFill(['balance' => $total, 'last_entry_id' => $row->last_id ?? null])->save();
                    $fixed++;
                }
            }

            return $fixed;
        });
    }

    /**
     * @return list<object{id: int, entries_sum: int, total_debit: int, total_credit: int}>
     */
    private function unbalancedTransactions(): array
    {
        return DB::table('ledger_transactions as t')
            ->leftJoin('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
            ->groupBy('t.id', 't.total_debit', 't.total_credit')
            ->selectRaw('t.id, COALESCE(SUM(e.amount), 0) AS entries_sum, t.total_debit, t.total_credit')
            ->havingRaw('COALESCE(SUM(e.amount), 0) <> 0')
            ->orHavingRaw('COALESCE(SUM(CASE WHEN e.amount > 0 THEN e.amount END), 0) <> t.total_debit')
            ->orHavingRaw('COALESCE(-SUM(CASE WHEN e.amount < 0 THEN e.amount END), 0) <> t.total_credit')
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * @return list<object{code: string, cached: int, actual: int}>
     */
    private function cacheMismatches(): array
    {
        return DB::table('ledger_accounts as a')
            ->leftJoin('ledger_account_balances as b', 'b.ledger_account_id', '=', 'a.id')
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('ledger_account_id, SUM(amount) AS total')->groupBy('ledger_account_id'),
                'e',
                'e.ledger_account_id',
                '=',
                'a.id',
            )
            ->selectRaw('a.code, COALESCE(b.balance, 0) AS cached, COALESCE(e.total, 0) AS actual')
            ->whereRaw('COALESCE(b.balance, 0) <> COALESCE(e.total, 0)')
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * @return list<object{code: string, balance: int}>
     */
    private function negativeProviderBalances(): array
    {
        return DB::table('ledger_accounts as a')
            ->join('ledger_entries as e', 'e.ledger_account_id', '=', 'a.id')
            ->where('a.type', 'provider_available')
            ->groupBy('a.code')
            ->selectRaw('a.code, SUM(e.amount) AS balance')
            ->havingRaw('SUM(e.amount) < 0')
            ->get()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function pendingRefunds(): array
    {
        return EscrowHold::query()
            ->whereIn('state', [EscrowHold::REFUNDED, EscrowHold::PARTIALLY_REFUNDED])
            ->where('refunded_amount', '>', 0)
            ->where('settled_at', '<', now()->subHour())
            ->whereNotExists(fn ($q) => $q->from('ledger_transactions')->whereRaw("idempotency_key = CONCAT('refund:paid:hold:', escrow_holds.id)"))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function entriesBalance(string $code): int
    {
        return (int) DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('a.code', $code)
            ->sum('e.amount');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{check: string, severity: string, message: string, data: array<string, mixed>}
     */
    private function issue(string $check, string $severity, string $message, array $data): array
    {
        return compact('check', 'severity', 'message', 'data');
    }
}
