<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de levantamento do técnico. O valor sai logo do saldo disponível para "levantamentos pendentes" e só sai
 * da plataforma quando a equipa paga; se recusar, volta ao saldo (estorno).
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property int $amount
 * @property string $destination
 * @property string $status
 * @property int|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $payout_reference
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ProviderProfile $provider
 * @property-read User|null $decider
 */
#[Fillable(['provider_profile_id', 'amount', 'destination'])]
class Withdrawal extends Model
{
    public const REQUESTED = 'requested';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    protected $attributes = ['status' => self::REQUESTED];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function label(): string
    {
        return match ($this->status) {
            self::REQUESTED => 'Pedido',
            self::PAID => 'Pago',
            default => 'Recusado',
        };
    }
}
