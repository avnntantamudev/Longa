<?php

declare(strict_types=1);

namespace App\Modules\Companies\Reports;

use App\Modules\Companies\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Formações dos colaboradores de uma empresa no período (aulas concluídas, pela data da aula).
 *
 * Só entram colaboradores que AUTORIZARAM a empresa (consent_status = accepted). Mostra apenas o
 * necessário para RH: data, área, formador, duração e custo. Nunca chat, local exato ou telefone do formador.
 */
class CompanyServicesReport
{
    /**
     * @return array{
     *     from: string, to: string,
     *     totals: array{employees: int, consented: int, lessons: int, hours: float, cost: int},
     *     by_employee: list<array{name: string, department: string, lessons: int, hours: float, cost: int}>,
     *     by_department: list<array{department: string, employees: int, lessons: int, hours: float, cost: int}>,
     *     lessons: list<array{date: string, employee: string, department: string, category: string, provider: string, hours: float, cost: int}>
     * }
     */
    public function build(Company $company, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $base = DB::table('company_employees as ce')
            ->join('service_jobs as s', 's.client_id', '=', 'ce.user_id')
            ->join('categories as k', 'k.id', '=', 's.category_id')
            ->join('provider_profiles as tp', 'tp.id', '=', 's.provider_profile_id')
            ->join('users as tu', 'tu.id', '=', 'tp.user_id')
            ->where('ce.company_id', $company->id)
            ->where('ce.consent_status', 'accepted')
            ->whereNotNull('ce.user_id')
            ->where('s.state', 'completed')
            ->whereBetween('s.scheduled_at', [$from, $to]);

        $lessons = (clone $base)
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
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function sections(array $report): array
    {
        $hours = fn (float $h) => number_format($h, 1, ',', '.').' h';
        $t = $report['totals'];

        return [
            ['title' => 'Resumo', 'headers' => ['Indicador', 'Valor'], 'rows' => [
                ['Colaboradores ativos', $t['employees']],
                ['Autorizaram a empresa', $t['consented']],
                ['Aulas concluídas', $t['lessons']],
                ['Horas de formação', $hours($t['hours'])],
                ['Custo (Kz)', $t['cost']],
            ]],
            ['title' => 'Por departamento', 'headers' => ['Departamento', 'Colaboradores', 'Aulas', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['employees'], $d['lessons'], $hours($d['hours']), $d['cost']], $report['by_department'])],
            ['title' => 'Por colaborador', 'headers' => ['Colaborador', 'Departamento', 'Aulas', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($e) => [$e['name'], $e['department'], $e['lessons'], $hours($e['hours']), $e['cost']], $report['by_employee'])],
            ['title' => 'Aulas', 'headers' => ['Data', 'Colaborador', 'Área', 'Formador', 'Horas', 'Custo (Kz)'],
                'rows' => array_map(fn ($l) => [$l['date'], $l['employee'], $l['category'], $l['provider'], $hours($l['hours']), $l['cost']], $report['lessons'])],
        ];
    }
}
