<?php

declare(strict_types=1);

namespace App\Modules\Admin\Reports;

use App\Modules\Wallet\Ledger\Accounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Relatório financeiro, calculado só a partir do ledger (a mesma fonte da reconciliação).
 *
 * Movimentos no período = lançamentos com data no intervalo; saldos = acumulado até ao fim do período.
 */
class FinancialReport
{
    /**
     * @return array{
     *     from: string, to: string,
     *     totals: array<string, int>,
     *     months: list<array{month: string, paid_in: int, commissions: int, vip: int, refunds: int, to_trainers: int}>
     * }
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $moved = fn (string $code, string $sign, array $types = []) => (int) $this->entries($from, $to)
            ->where('a.code', $code)
            ->where('e.amount', $sign === '+' ? '>' : '<', 0)
            ->when($types !== [], fn ($q) => $q->whereIn('t.type', $types))
            ->sum('e.amount');

        $trainerNet = (int) $this->entries($from, $to)->where('a.type', 'trainer_available')->whereIn('t.type', ['escrow_release', 'refund_partial'])->sum('e.amount');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                // Dinheiro que os alunos pagaram pela app (entrou em escrow)
                'paid_in' => $moved(Accounts::ESCROW, '+', ['escrow_hold']),
                'commissions' => (int) $this->entries($from, $to)->where('a.code', Accounts::PLATFORM_REVENUE)->sum('e.amount'),
                'vip' => (int) $this->entries($from, $to)->where('a.code', Accounts::SUBSCRIPTION_REVENUE)->sum('e.amount'),
                'to_trainers' => $trainerNet,
                'refunds_decided' => $moved(Accounts::REFUNDS_PAYABLE, '+'),
                'refunds_paid' => -$moved(Accounts::REFUNDS_PAYABLE, '-'),
                'cash_commissions_received' => -$moved(Accounts::CASH_SETTLEMENT, '-', ['cash_commission_received']),
                // Saldos no fim do período
                'escrow_at_end' => $this->balanceAt(Accounts::ESCROW, $to),
                'refunds_pending_at_end' => $this->balanceAt(Accounts::REFUNDS_PAYABLE, $to),
                'cash_commissions_due_at_end' => -(int) DB::table('ledger_entries as e')
                    ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
                    ->where('a.type', 'trainer_commission_due')->where('e.created_at', '<=', $to)->sum('e.amount'),
                'trainers_available_at_end' => (int) DB::table('ledger_entries as e')
                    ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
                    ->where('a.type', 'trainer_available')->where('e.created_at', '<=', $to)->sum('e.amount'),
            ],
            'months' => $this->months($from, $to),
        ];
    }

    /**
     * @return list<array{month: string, paid_in: int, commissions: int, vip: int, refunds: int, to_trainers: int}>
     */
    private function months(Carbon $from, Carbon $to): array
    {
        $rows = $this->entries($from, $to)
            ->selectRaw("DATE_FORMAT(e.created_at, '%Y-%m') as month")
            ->selectRaw("SUM(CASE WHEN a.code = ? AND t.type = 'escrow_hold' THEN e.amount ELSE 0 END) as paid_in", [Accounts::ESCROW])
            ->selectRaw('SUM(CASE WHEN a.code = ? THEN e.amount ELSE 0 END) as commissions', [Accounts::PLATFORM_REVENUE])
            ->selectRaw('SUM(CASE WHEN a.code = ? THEN e.amount ELSE 0 END) as vip', [Accounts::SUBSCRIPTION_REVENUE])
            ->selectRaw('SUM(CASE WHEN a.code = ? AND e.amount > 0 THEN e.amount ELSE 0 END) as refunds', [Accounts::REFUNDS_PAYABLE])
            ->selectRaw("SUM(CASE WHEN a.type = 'trainer_available' AND t.type IN ('escrow_release', 'refund_partial') THEN e.amount ELSE 0 END) as to_trainers")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($r) => [
            'month' => (string) $r->month,
            'paid_in' => (int) $r->paid_in,
            'commissions' => (int) $r->commissions,
            'vip' => (int) $r->vip,
            'refunds' => (int) $r->refunds,
            'to_trainers' => (int) $r->to_trainers,
        ])->values()->all();
    }

    private function entries(Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        return DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->whereBetween('e.created_at', [$from, $to]);
    }

    private function balanceAt(string $code, Carbon $at): int
    {
        return (int) DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('a.code', $code)->where('e.created_at', '<=', $at)->sum('e.amount');
    }
}
