<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Services;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Carteira do técnico (só leitura nesta fase; levantamentos na Fase 5). Tudo derivado do ledger.
 */
class WalletSummary
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{available: int, pending: int, total_earned: int, commission_due: int, currency: string}
     */
    public function summary(ProviderProfile $provider): array
    {
        $availableId = $this->accountId(Accounts::providerAvailable($provider));

        return [
            'available' => $this->ledger->balance(Accounts::providerAvailable($provider)),
            // Líquido dos serviços pagos ainda por confirmar (retidos em escrow)
            'pending' => (int) EscrowHold::query()->where('provider_profile_id', $provider->id)->whereIn('state', EscrowHold::ACTIVE)->sum('provider_net'),
            // Tudo o que entrou na carteira (antes de compensações de comissão e levantamentos)
            'total_earned' => $availableId === null ? 0 : (int) LedgerEntry::query()->where('ledger_account_id', $availableId)->where('amount', '>', 0)->sum('amount'),
            'commission_due' => -$this->ledger->balance(Accounts::providerCommissionDue($provider)),
            'currency' => 'AOA',
        ];
    }

    /**
     * Movimentos da carteira e da dívida de comissões, mais recentes primeiro.
     *
     * @return CursorPaginator<int, LedgerEntry>
     */
    public function movements(ProviderProfile $provider, int $perPage = 20): CursorPaginator
    {
        $ids = array_filter([
            $this->accountId(Accounts::providerAvailable($provider)),
            $this->accountId(Accounts::providerCommissionDue($provider)),
        ]);

        return LedgerEntry::query()
            ->whereIn('ledger_account_id', $ids === [] ? [0] : $ids)
            ->with(['account', 'transaction'])
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    private function accountId(string $code): ?int
    {
        $id = LedgerAccount::query()->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }
}
