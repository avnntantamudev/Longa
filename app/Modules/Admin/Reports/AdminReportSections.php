<?php

declare(strict_types=1);

namespace App\Modules\Admin\Reports;

use App\Modules\Requests\Enums\RequestState;

/**
 * Converte os relatórios do admin em secções (título + tabela) para o ecrã e a exportação.
 */
class AdminReportSections
{
    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function financial(array $report): array
    {
        $t = $report['totals'];

        return [
            ['title' => 'Resumo do período', 'headers' => ['Indicador', 'Kz'], 'rows' => [
                ['Pago pelos clientes na app (entrou em escrow)', $t['paid_in']],
                ['Comissões do BISCA', $t['commissions']],
                ['Receita VIP', $t['vip']],
                ['Creditado aos técnicos', $t['to_providers']],
                ['Reembolsos decididos', $t['refunds_decided']],
                ['Reembolsos pagos', $t['refunds_paid']],
                ['Comissões em mão recebidas', $t['cash_commissions_received']],
            ]],
            ['title' => 'Saldos no fim do período', 'headers' => ['Indicador', 'Kz'], 'rows' => [
                ['Retido em escrow', $t['escrow_at_end']],
                ['Reembolsos por executar', $t['refunds_pending_at_end']],
                ['Comissões em mão por receber', $t['cash_commissions_due_at_end']],
                ['Saldo disponível dos técnicos', $t['providers_available_at_end']],
            ]],
            ['title' => 'Por mês', 'headers' => ['Mês', 'Pago na app', 'Comissões', 'VIP', 'Reembolsos', 'Para técnicos'],
                'rows' => array_map(fn ($m) => [$m['month'], $m['paid_in'], $m['commissions'], $m['vip'], $m['refunds'], $m['to_providers']], $report['months'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function operations(array $report): array
    {
        $r = $report['requests'];
        $avg = $r['avg_seconds_to_accept'];

        return [
            ['title' => 'Pedidos', 'headers' => ['Indicador', 'Valor'], 'rows' => [
                ['Publicados', $r['total']], ['Aceites', $r['matched']], ['Expirados sem técnico', $r['expired']],
                ['Cancelados pelo cliente', $r['cancelled']], ['Para outro dia (agendados)', $r['scheduled']],
                ['Taxa de aceitação', number_format((float) $r['acceptance_rate'], 1, ',', '').'%'],
                ['Tempo médio até aceitar (hoje)', $avg === null ? '—' : gmdate($avg >= 3600 ? 'G\h i\m' : 'i\m s\s', $avg)],
            ]],
            ['title' => 'Serviços por estado', 'headers' => ['Estado', 'Serviços'],
                'rows' => [...array_map(fn ($state, $n) => [RequestState::tryFrom((string) $state)?->label() ?? $state, $n], array_keys($report['sessions']['by_state']), $report['sessions']['by_state']),
                    ['Cancelamentos em cima da hora', $report['sessions']['late_cancellations']]]],
            ['title' => 'Disputas', 'headers' => ['Indicador', 'Valor'], 'rows' => [
                ['Abertas no período', $report['disputes']['opened']], ['Por decidir agora', $report['disputes']['open_now']],
                ['Libertadas ao técnico', $report['disputes']['by_resolution']['released'] ?? 0],
                ['Reembolso total', $report['disputes']['by_resolution']['refunded'] ?? 0],
                ['Reembolso parcial', $report['disputes']['by_resolution']['partially_refunded'] ?? 0],
            ]],
            ['title' => 'Áreas mais pedidas', 'headers' => ['Área', 'Pedidos', 'Aceites'], 'rows' => array_map(fn ($x) => [$x['name'], $x['requests'], $x['matched']], $report['by_category'])],
            ['title' => 'Por local', 'headers' => ['Província', 'Município', 'Pedidos', 'Aceites'], 'rows' => array_map(fn ($x) => [$x['province'], $x['municipality'], $x['requests'], $x['matched']], $report['by_place'])],
            ['title' => 'Técnicos mais ativos', 'headers' => ['Técnico', 'Serviços concluídos', 'Líquido (Kz)'], 'rows' => array_map(fn ($x) => [$x['name'], $x['lessons'], $x['net']], $report['top_providers'])],
            ['title' => 'Clientes mais ativos', 'headers' => ['Cliente', 'Serviços concluídos', 'Pago (Kz)'], 'rows' => array_map(fn ($x) => [$x['name'], $x['lessons'], $x['spent']], $report['top_clients'])],
        ];
    }
}
