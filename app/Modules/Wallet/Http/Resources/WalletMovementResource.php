<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Sessions\Models\TrainingSession;
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
            'bucket' => $this->account->type === 'trainer_available' ? 'available' : 'commission_due',
            'type' => $tx->type,
            'label' => self::label($tx->type),
            'amount' => $this->amount,
            'session_id' => $tx->reference_type === TrainingSession::class ? $tx->reference_id : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'escrow_release' => 'Aula confirmada',
            'refund_partial' => 'Parte de aula cancelada ou em disputa',
            'cash_commission_due' => 'Comissão de aula paga em mão',
            'cash_commission_received' => 'Comissão entregue ao Garimpo',
            'commission_offset' => 'Comissão descontada do saldo',
            'reversal' => 'Estorno',
            default => 'Movimento',
        };
    }
}
