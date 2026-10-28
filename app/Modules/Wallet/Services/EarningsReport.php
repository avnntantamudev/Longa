<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Services;

use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Wallet\Ledger\Accounts;
use Illuminate\Support\Facades\DB;

/**
 * Ganhos do formador por mês (um ano). Os valores em dinheiro vêm do ledger; o número de aulas das aulas.
 *  - creditado: o que entrou na carteira (aulas confirmadas e partes de cancelamentos/disputas);
 *  - comissões: comissão do BISCA das aulas deste formador (incluindo as pagas em mão);
 *  - em mão: valor das aulas pagas diretamente pelo aluno.
 */
class EarningsReport
{
    /**
     * @return array{year: int, months: list<array{month: int, lessons: int, cash_lessons: int, credited: int, commissions: int, cash_received: int}>, totals: array{lessons: int, cash_lessons: int, credited: int, commissions: int, cash_received: int}}
     */
    public function build(TrainerProfile $trainer, int $year): array
    {
        $months = array_fill(1, 12, ['lessons' => 0, 'cash_lessons' => 0, 'credited' => 0, 'commissions' => 0, 'cash_received' => 0]);

        $lessons = DB::table('training_sessions')
            ->where('trainer_profile_id', $trainer->id)->where('state', 'completed')->whereYear('scheduled_at', $year)
            ->selectRaw("MONTH(scheduled_at) as m, COUNT(*) as lessons, SUM(payment_method = 'cash') as cash_lessons, SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_received")
            ->groupBy('m')->get();
        foreach ($lessons as $row) {
            $months[(int) $row->m] = [...$months[(int) $row->m], 'lessons' => (int) $row->lessons, 'cash_lessons' => (int) $row->cash_lessons, 'cash_received' => (int) $row->cash_received];
        }

        $credited = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->where('a.code', Accounts::trainerAvailable($trainer))
            ->whereIn('t.type', ['escrow_release', 'refund_partial'])
            ->whereYear('e.created_at', $year)
            ->selectRaw('MONTH(e.created_at) as m, SUM(e.amount) as total')->groupBy('m')->get();
        foreach ($credited as $row) {
            $months[(int) $row->m]['credited'] = (int) $row->total;
        }

        $commissions = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->join('training_sessions as s', fn ($j) => $j->on('s.id', '=', 't.reference_id')->where('t.reference_type', '=', TrainingSession::class))
            ->where('a.code', Accounts::PLATFORM_REVENUE)
            ->where('s.trainer_profile_id', $trainer->id)
            ->whereIn('t.type', ['escrow_release', 'refund_partial', 'cash_commission_due'])
            ->whereYear('e.created_at', $year)
            ->selectRaw('MONTH(e.created_at) as m, SUM(e.amount) as total')->groupBy('m')->get();
        foreach ($commissions as $row) {
            $months[(int) $row->m]['commissions'] = (int) $row->total;
        }

        $list = [];
        foreach ($months as $m => $values) {
            $list[] = ['month' => $m, ...$values];
        }

        return [
            'year' => $year,
            'months' => $list,
            'totals' => [
                'lessons' => array_sum(array_column($list, 'lessons')),
                'cash_lessons' => array_sum(array_column($list, 'cash_lessons')),
                'credited' => array_sum(array_column($list, 'credited')),
                'commissions' => array_sum(array_column($list, 'commissions')),
                'cash_received' => array_sum(array_column($list, 'cash_received')),
            ],
        ];
    }

    /**
     * @param  array{year: int, months: list<array<string, int>>, totals: array<string, int>}  $report
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function sections(array $report): array
    {
        $names = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $headers = ['Mês', 'Aulas', 'Em mão', 'Creditado na carteira', 'Comissões do BISCA', 'Recebido em mão'];
        $rows = array_map(fn ($m) => [$names[$m['month']], $m['lessons'], $m['cash_lessons'], $m['credited'], $m['commissions'], $m['cash_received']], $report['months']);
        $t = $report['totals'];

        return [['title' => "Ganhos de {$report['year']}", 'headers' => $headers, 'rows' => [...$rows, ['Total', $t['lessons'], $t['cash_lessons'], $t['credited'], $t['commissions'], $t['cash_received']]]]];
    }
}
