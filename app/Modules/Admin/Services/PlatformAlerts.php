<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Ratings\Models\ProviderSanction;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Sessions\Models\Message;
use App\Modules\Wallet\Ledger\Accounts;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Alertas do painel (BISCA, Fase 3.6): o que a equipa tem por rever e os padrões suspeitos de desvio da plataforma.
 */
class PlatformAlerts
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * Contadores do que está por rever.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'Orçamentos fora do intervalo' => Quote::query()->where('outside_reference', true)->where('status', Quote::SENT)->count(),
            'Mensagens sinalizadas' => Message::query()->where('flagged', true)->where('flag_status', Message::FLAG_PENDING)->count(),
            'Adendas em revisão' => QuoteAddendum::query()->where('status', QuoteAddendum::PENDING_TEAM)->count(),
            'Remoções por rever' => ProviderSanction::query()->where('status', ProviderSanction::PENDING_REVIEW)->count(),
        ];
    }

    /**
     * Padrão de desvio: serviços cancelados pouco depois de o técnico aceitar (quando as partes já podem falar).
     *
     * @return list<array{name: string, phone: string, cancellations: int}>
     */
    public function suspiciousCancellations(): array
    {
        $config = config('longa.alerts');

        return DB::table('service_requests as r')
            ->join('provider_profiles as p', 'p.id', '=', 'r.provider_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('r.state', 'cancelled')
            ->whereIn('r.closed_reason', ['cancelled_by_client', 'cancelled_by_provider'])
            ->where('r.cancelled_at', '>=', now()->subDays((int) $config['cancel_days']))
            ->whereRaw('TIMESTAMPDIFF(MINUTE, r.matched_at, r.cancelled_at) <= ?', [(int) $config['cancel_window_hours'] * 60])
            ->groupBy('p.id', 'u.name', 'u.phone')
            ->havingRaw('COUNT(*) >= ?', [(int) $config['cancel_count']])
            ->orderByRaw('COUNT(*) DESC')
            ->limit(50)
            ->selectRaw('u.name, u.phone, COUNT(*) as cancellations')
            ->get()
            ->map(fn (object $r) => ['name' => (string) $r->name, 'phone' => (string) $r->phone, 'cancellations' => (int) $r->cancellations])
            ->all();
    }

    /**
     * Técnicos com saldo líquido negativo (os que estão abaixo do mínimo deixam de receber pedidos).
     *
     * @return list<array{name: string, phone: string, net: int, blocked: bool}>
     */
    public function lowBalances(): array
    {
        $min = $this->settings->int('dispatch_min_balance');

        return DB::table('ledger_accounts as a')
            ->join('ledger_account_balances as b', 'b.ledger_account_id', '=', 'a.id')
            ->join('provider_profiles as p', 'p.id', '=', 'a.owner_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('a.type', [Accounts::PROVIDER_AVAILABLE, Accounts::PROVIDER_COMMISSION_DUE])
            ->groupBy('p.id', 'u.name', 'u.phone')
            ->havingRaw('SUM(b.balance) < 0')
            ->orderByRaw('SUM(b.balance)')
            ->limit(50)
            ->selectRaw('u.name, u.phone, SUM(b.balance) as net')
            ->get()
            ->map(fn (object $r) => ['name' => (string) $r->name, 'phone' => (string) $r->phone, 'net' => (int) $r->net, 'blocked' => (int) $r->net < $min])
            ->all();
    }
}
