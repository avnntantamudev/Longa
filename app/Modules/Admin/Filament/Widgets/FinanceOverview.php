<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Widgets;

use App\Modules\Admin\Filament\Concerns\AdminOnly;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerAccountBalance;
use App\Modules\Wallet\Models\LedgerReconciliation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinanceOverview extends StatsOverviewWidget
{
    /** Saldos e receita: só o admin. */
    public static function canView(): bool
    {
        return static::canAccess();
    }

    use AdminOnly;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $ledger = app(LedgerService::class);
        $last = LedgerReconciliation::query()->latest('id')->first();
        $commissionsDue = -(int) LedgerAccountBalance::query()
            ->whereHas('account', fn ($q) => $q->where('type', 'provider_commission_due'))
            ->sum('balance');

        return [
            Stat::make('Retido em escrow', SessionSections::kz($ledger->balance(Accounts::ESCROW))),
            Stat::make('Receita (comissões)', SessionSections::kz($ledger->balance(Accounts::PLATFORM_REVENUE))),
            Stat::make('Comissões a receber em mão', SessionSections::kz($commissionsDue)),
            Stat::make('Saldos das carteiras', SessionSections::kz((int) LedgerAccountBalance::query()
                ->whereHas('account', fn ($q) => $q->where('type', Accounts::PROVIDER_AVAILABLE))
                ->sum('balance'))),
            Stat::make('Levantamentos pendentes', SessionSections::kz($ledger->balance(Accounts::WITHDRAWALS_PENDING))),
            Stat::make('Disputas abertas', (string) Dispute::query()->where('status', Dispute::OPEN)->count()),
            Stat::make('Última reconciliação', $last === null ? 'Nunca' : ($last->isOk() ? 'OK' : 'Diferenças'))
                ->description($last?->checked_at->diffForHumans())
                ->color($last === null || $last->isOk() ? 'success' : 'danger'),
        ];
    }
}
