<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Wallet\Models\LedgerTransaction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retenção do pagamento de um serviço. O dinheiro está na conta "escrow" do ledger enquanto o
 * estado é held ou disputed; a liquidação (libertar / reembolsar) é uma transação do ledger.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $client_id
 * @property int $provider_profile_id
 * @property int $amount
 * @property int $commission
 * @property int $provider_net
 * @property int $commission_percent
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $hold_transaction_id
 * @property int|null $settlement_transaction_id
 * @property int $refunded_amount
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property-read ServiceRequest $session
 */
#[Fillable(['service_request_id', 'client_id', 'provider_profile_id', 'amount', 'commission', 'provider_net', 'commission_percent', 'expires_at', 'hold_transaction_id'])]
class EscrowHold extends Model
{
    public const HELD = 'held';

    public const DISPUTED = 'disputed';

    public const RELEASED = 'released';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    /** Estados em que o dinheiro continua na conta escrow */
    public const ACTIVE = [self::HELD, self::DISPUTED];

    protected $attributes = ['state' => self::HELD, 'refunded_amount' => 0];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'settlement_transaction_id');
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE, true);
    }
}
