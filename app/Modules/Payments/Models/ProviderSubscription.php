<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Users\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscrição VIP do técnico (anual por omissão). Renovar soma a duração ao fim da subscrição em vigor.
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property string $plan
 * @property string $status
 * @property string $source
 * @property int $amount
 * @property int $duration_days
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property string|null $method
 * @property string|null $gateway
 * @property string|null $external_reference
 * @property string $idempotency_key
 * @property string|null $failure_reason
 * @property int|null $ledger_transaction_id
 * @property int|null $created_by
 * @property string|null $note
 * @property list<int>|null $reminders_sent
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ProviderProfile $provider
 */
#[Fillable(['provider_profile_id', 'plan', 'status', 'source', 'amount', 'duration_days', 'method', 'gateway', 'idempotency_key', 'created_by', 'note'])]
class ProviderSubscription extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminders_sent' => 'array'];
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }
}
