<?php

declare(strict_types=1);

namespace App\Modules\Companies\Reports;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serviços dos colaboradores de uma empresa no período (serviços pagos, pela data do serviço), em duas partes:
 *  - pedidos: serviços que o colaborador pediu como cliente (custo para a empresa);
 *  - prestados: serviços que o colaborador fez como técnico (valor faturado e líquido depois da comissão).
 *
 * Só entram colaboradores ativos que AUTORIZARAM a empresa (consent_status = accepted). Mostra apenas o
 * necessário para RH: data, área, duração e valores (e o técnico, nos pedidos). Nunca chat, local exato,
 * telefones ou o nome do cliente dos serviços prestados.
 */
class CompanyServicesReport
{
    /**
     * @return array{
     *     from: string, to: string,
     *     totals: array{employees: int, consented: int, lessons: int, hours: float, cost: int},
     *     by_employee: list<array{name: string, department: string, lessons: int, hours: float, cost: int}>,
     *     by_department: list<array{department: string, employees: int, lessons: int, hours: float, cost: int}>,
     *     lessons: list<array{date: string, employee: string, department: string, category: string, provider: string, hours: float, cost: int}>,
     *     provided: array{
     *         totals: array{services: int, hours: float, amount: int, net: int},
     *         by_employee: list<array{name: string, department: string, services: int, hours: float, amount: int, net: int}>,
     *         services: list<array{date: string, employee: string, department: string, category: string, hours: float, amount: int, net: int}>
     *     }
     * }
     */
    public function build(Company $company, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        // Pedidos pelo colaborador (como cliente)
        $lessons = $this->base($company)
            ->join('service_requests as s', 's.client_id', '=', 'ce.user_id')
            ->join('provider_profiles as tp', 'tp.id', '=', 's.provider_profile_id')
            ->join('users as tu', 'tu.id', '=', 'tp.user_id')
            ->join('categories as k', 'k.id', '=', 's.category_id')
            ->where('s.state', 'paid')
            ->where(fn ($q) => $q->whereNull('s.lesson_schedule')->orWhereNotNull('s.package_id')) // pacotes: contam as aulas
            ->whereBetween('s.scheduled_at', [$from, $to])
            ->orderBy('s.scheduled_at')
            ->get(['s.scheduled_at', 'ce.name', 'ce.department', 'k.name as category', 'tu.name as provider', 's.duration_minutes', 's.amount'])
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->scheduled_at)->format('d/m/Y'),
                'employee' => (string) $r->name,
                'department' => (string) ($r->department ?? '—'),
                'category' => (string) $r->category,
                'provider' => (string) $r->provider,
                'hours' => round(((int) $r->duration_minutes) / 60, 2),
                'cost' => (int) $r->amount,
            ])->all();

        // Prestados pelo colaborador (como técnico)
        $provided = $this->base($company)
            ->join('provider_profiles as tp', 'tp.user_id', '=', 'ce.user_id')
            ->join('service_requests as s', 's.provider_profile_id', '=', 'tp.id')
            ->join('categories as k', 'k.id', '=', 's.category_id')
            ->where('s.state', 'paid')
            ->where(fn ($q) => $q->whereNull('s.lesson_schedule')->orWhereNotNull('s.package_id')) // pacotes: contam as aulas
            ->whereBetween('s.scheduled_at', [$from, $to])
            ->orderBy('s.scheduled_at')
            ->get(['s.scheduled_at', 'ce.name', 'ce.department', 'k.name as category', 's.duration_minutes', 's.amount', 's.commission', 's.provider_net'])
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->scheduled_at)->format('d/m/Y'),
                'employee' => (string) $r->name,
                'department' => (string) ($r->department ?? '—'),
                'category' => (string) $r->category,
                'hours' => round(((int) $r->duration_minutes) / 60, 2),
                'amount' => (int) $r->amount,
                'net' => (int) ($r->provider_net ?? ((int) $r->amount - (int) $r->commission)),
            ])->all();

        $byEmployee = collect($lessons)->groupBy('employee')->map(fn ($rows, $name) => [
            'name' => (string) $name,
            'department' => (string) $rows->first()['department'],
            'lessons' => $rows->count(),
            'hours' => round((float) $rows->sum('hours'), 2),
            'cost' => (int) $rows->sum('cost'),
        ])->sortByDesc('hours')->values()->all();

        $byDepartment = collect($lessons)->groupBy('department')->map(fn ($rows, $dep) => [
            'department' => (string) $dep,
            'employees' => $rows->pluck('employee')->unique()->count(),
            'lessons' => $rows->count(),
            'hours' => round((float) $rows->sum('hours'), 2),
            'cost' => (int) $rows->sum('cost'),
        ])->sortByDesc('cost')->values()->all();

        $providedByEmployee = collect($provided)->groupBy('employee')->map(fn ($rows, $name) => [
            'name' => (string) $name,
            'department' => (string) $rows->first()['department'],
            'services' => $rows->count(),
            'hours' => round((float) $rows->sum('hours'), 2),
            'amount' => (int) $rows->sum('amount'),
            'net' => (int) $rows->sum('net'),
        ])->sortByDesc('amount')->values()->all();

        $employees = DB::table('company_employees')->where('company_id', $company->id)->where('status', 'active');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'employees' => (clone $employees)->count(),
                'consented' => (clone $employees)->where('consent_status', 'accepted')->count(),
                'lessons' => count($lessons),
                'hours' => round((float) collect($lessons)->sum('hours'), 2),
                'cost' => (int) collect($lessons)->sum('cost'),
            ],
            'by_employee' => $byEmployee,
            'by_department' => $byDepartment,
            'lessons' => $lessons,
            'provided' => [
                'totals' => [
                    'services' => count($provided),
                    'hours' => round((float) collect($provided)->sum('hours'), 2),
                    'amount' => (int) collect($provided)->sum('amount'),
                    'net' => (int) collect($provided)->sum('net'),
                ],
                'by_employee' => $providedByEmployee,
                'services' => $provided,
            ],
        ];
    }

    /**
     * Colaboradores ativos da empresa que autorizaram e já têm conta ligada.
     */
    private function base(Company $company): Builder
    {
        return DB::table('company_employees as ce')
            ->where('ce.company_id', $company->id)
            ->where('ce.consent_status', 'accepted')
            ->where('ce.status', 'active') // desativados ficam fora, como nos totais
            ->whereNotNull('ce.user_id');
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function sections(array $report): array
    {
        $hours = fn (float $h) => number_format($h, 1, ',', '.').' h';
        $t = $report['totals'];
        $p = $report['provided'];

        return [
            ['title' => 'Resumo', 'headers' => ['Indicador', 'Valor'], 'rows' => [
                ['Colaboradores ativos', $t['employees']],
                ['Autorizaram a empresa', $t['consented']],
                ['Serviços pedidos', $t['lessons']],
                ['Horas (pedidos)', $hours($t['hours'])],
                ['Custo dos pedidos (Kz)', $t['cost']],
                ['Serviços prestados', $p['totals']['services']],
                ['Horas (prestados)', $hours($p['totals']['hours'])],
                ['Faturado (Kz)', $p['totals']['amount']],
                ['Líquido para os técnicos (Kz)', $p['totals']['net']],
            ]],
            ['title' => 'Pedidos por departamento', 'headers' => ['Departamento', 'Colaboradores', 'Serviços', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['employees'], $d['lessons'], $hours($d['hours']), $d['cost']], $report['by_department'])],
            ['title' => 'Pedidos por colaborador', 'headers' => ['Colaborador', 'Departamento', 'Serviços', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($e) => [$e['name'], $e['department'], $e['lessons'], $hours($e['hours']), $e['cost']], $report['by_employee'])],
            ['title' => 'Serviços pedidos', 'headers' => ['Data', 'Colaborador', 'Área', 'Técnico', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($l) => [$l['date'], $l['employee'], $l['category'], $l['provider'], $hours($l['hours']), $l['cost']], $report['lessons'])],
            ['title' => 'Prestados por colaborador', 'headers' => ['Colaborador', 'Departamento', 'Serviços', 'Horas', 'Faturado (Kz)', 'Líquido (Kz)'],
                'rows' => array_map(fn ($e) => [$e['name'], $e['department'], $e['services'], $hours($e['hours']), $e['amount'], $e['net']], $p['by_employee'])],
            ['title' => 'Serviços prestados', 'headers' => ['Data', 'Colaborador', 'Área', 'Horas', 'Faturado (Kz)', 'Líquido (Kz)'],
                'rows' => array_map(fn ($s) => [$s['date'], $s['employee'], $s['category'], $hours($s['hours']), $s['amount'], $s['net']], $p['services'])],
        ];
    }
}
