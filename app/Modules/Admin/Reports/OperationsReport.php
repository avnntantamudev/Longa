<?php

declare(strict_types=1);

namespace App\Modules\Admin\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Relatório de operação: pedidos (pela data de criação) e serviços (pela data marcada) no período.
 */
class OperationsReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $requests = DB::table('service_requests')->whereBetween('created_at', [$from, $to]);
        $byState = (clone $requests)->selectRaw('state, COUNT(*) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n);
        $total = (int) $byState->sum();
        $matched = (int) ($byState['matched'] ?? 0);

        $avgSeconds = DB::table('service_requests as r')
            ->join('service_jobs as s', 's.service_request_id', '=', 'r.id')
            ->whereBetween('r.created_at', [$from, $to])
            ->where('r.scheduled', false)
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, r.created_at, s.created_at)) as avg')
            ->value('avg');

        $sessions = DB::table('service_jobs')->whereBetween('scheduled_at', [$from, $to]);
        $sessionStates = (clone $sessions)->selectRaw('state, COUNT(*) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n);

        $disputes = DB::table('disputes')->whereBetween('created_at', [$from, $to]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'requests' => [
                'total' => $total,
                'matched' => $matched,
                'expired' => (int) ($byState['expired'] ?? 0),
                'cancelled' => (int) ($byState['cancelled'] ?? 0),
                'scheduled' => (int) (clone $requests)->where('scheduled', true)->count(),
                'acceptance_rate' => $total > 0 ? round($matched / $total * 100, 1) : 0.0,
                'avg_seconds_to_accept' => $avgSeconds === null ? null : (int) round((float) $avgSeconds),
            ],
            'sessions' => [
                'total' => (int) $sessionStates->sum(),
                'by_state' => $sessionStates->all(),
                'late_cancellations' => (int) DB::table('session_events as ev')
                    ->join('service_jobs as s', 's.id', '=', 'ev.service_job_id')
                    ->whereBetween('s.scheduled_at', [$from, $to])
                    ->where('ev.type', 'cancelled')
                    ->whereRaw("JSON_EXTRACT(ev.metadata, '$.late') = true")
                    ->count(),
            ],
            'disputes' => [
                'opened' => (int) (clone $disputes)->count(),
                'open_now' => (int) DB::table('disputes')->where('status', 'open')->count(),
                'by_resolution' => (clone $disputes)->whereNotNull('resolution')->selectRaw('resolution, COUNT(*) as n')->groupBy('resolution')->pluck('n', 'resolution')->map(fn ($n) => (int) $n)->all(),
            ],
            'by_category' => DB::table('service_requests as r')
                ->join('categories as k', 'k.id', '=', 'r.category_id')
                ->whereBetween('r.created_at', [$from, $to])
                ->selectRaw("k.name, COUNT(*) as requests, SUM(r.state = 'matched') as matched")
                ->groupBy('k.name')->orderByDesc('requests')->limit(15)->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'requests' => (int) $r->requests, 'matched' => (int) $r->matched])->all(),
            'by_place' => DB::table('service_requests')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("province, municipality, COUNT(*) as requests, SUM(state = 'matched') as matched")
                ->groupBy('province', 'municipality')->orderByDesc('requests')->limit(20)->get()
                ->map(fn ($r) => ['province' => (string) $r->province, 'municipality' => (string) $r->municipality, 'requests' => (int) $r->requests, 'matched' => (int) $r->matched])->all(),
            'top_providers' => DB::table('service_jobs as s')
                ->join('provider_profiles as tp', 'tp.id', '=', 's.provider_profile_id')
                ->join('users as u', 'u.id', '=', 'tp.user_id')
                ->whereBetween('s.scheduled_at', [$from, $to])->where('s.state', 'completed')
                ->selectRaw('u.name, COUNT(*) as lessons, SUM(s.provider_net) as net')
                ->groupBy('u.id', 'u.name')->orderByDesc('lessons')->limit(10)->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'lessons' => (int) $r->lessons, 'net' => (int) $r->net])->all(),
            'top_clients' => DB::table('service_jobs as s')
                ->join('users as u', 'u.id', '=', 's.client_id')
                ->whereBetween('s.scheduled_at', [$from, $to])->where('s.state', 'completed')
                ->selectRaw('u.name, COUNT(*) as lessons, SUM(s.amount) as spent')
                ->groupBy('u.id', 'u.name')->orderByDesc('lessons')->limit(10)->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'lessons' => (int) $r->lessons, 'spent' => (int) $r->spent])->all(),
        ];
    }
}
