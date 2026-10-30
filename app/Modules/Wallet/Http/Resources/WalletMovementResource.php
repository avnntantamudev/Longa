<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um movimento da carteira. `bucket`: available (saldo) ou commission_due (dívida de comissões em mão).
 *
 * @mixin LedgerEntry
 */
class WalletMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tx = $this->transaction;

        return [
            'id' => $this->id,
            'bucket' => $this->account->type === 'provider_available' ? 'available' : 'commission_due',
            'type' => $tx->type,
            /** service_credit | commission_debit | topup | withdrawal | reversal | adjustment */
            'kind' => self::kind($tx->type),
            'label' => self::label($tx->type),
            'amount' => $this->amount,
            'session_id' => $tx->reference_type === ServiceRequest::class ? $tx->reference_id : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Tipo do movimento da carteira (crédito de serviço, débito de comissão, recarga, levantamento, estorno, ajuste).
     */
    public static function kind(string $type): string
    {
        return match ($type) {
            'escrow_release', 'refund_partial' => 'service_credit',
            'cash_commission_due', 'commission_offset', 'cash_commission_received' => 'commission_debit',
            'wallet_topup' => 'topup',
            'withdrawal_requested', 'withdrawal_paid' => 'withdrawal',
            'reversal', 'withdrawal_rejected' => 'reversal',
            'adjustment' => 'adjustment',
            default => 'other',
        };
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'wallet_topup' => 'Recarga da carteira',
            'withdrawal_requested' => 'Levantamento pedido',
            'withdrawal_paid' => 'Levantamento pago',
            'withdrawal_rejected' => 'Levantamento recusado (estorno)',
            'adjustment' => 'Ajuste do BISCA',
            'escrow_release' => 'Serviço confirmado',
            'refund_partial' => 'Parte de serviço cancelado ou em disputa',
            'cash_commission_due' => 'Comissão de serviço pago em mão',
            'cash_commission_received' => 'Comissão entregue ao BISCA',
            'commission_offset' => 'Comissão descontada do saldo',
            'reversal' => 'Estorno',
            default => 'Movimento',
        };
    }
}
