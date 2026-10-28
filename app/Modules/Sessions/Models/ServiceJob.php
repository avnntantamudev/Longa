<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Aula criada quando um formador aceita um pedido. Estados e regras em docs/state-machines.md.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $client_id
 * @property int $provider_profile_id
 * @property int $category_id
 * @property string $title
 * @property int $amount
 * @property int $commission
 * @property int $provider_net
 * @property int $commission_percent
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property int $duration_minutes
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property string $payment_method
 * @property \Illuminate\Support\Carbon|null $payment_due_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $awaiting_since
 * @property \Illuminate\Support\Carbon|null $no_show_contest_until
 * @property \Illuminate\Support\Carbon|null $cash_confirmed_at
 * @property string $modality
 * @property string $province
 * @property string $municipality
 * @property string $neighborhood
 * @property float $latitude
 * @property float $longitude
 * @property SessionState $state
 * @property string|null $escrow_reference
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $client
 * @property-read ProviderProfile $provider
 * @property-read Category $category
 * @property-read Conversation|null $conversation
 */
#[Fillable([
    'service_request_id', 'client_id', 'provider_profile_id', 'category_id', 'title', 'amount', 'commission', 'provider_net', 'commission_percent',
    'scheduled_at', 'duration_minutes', 'ends_at', 'payment_method', 'payment_due_at', 'modality', 'province', 'municipality',
    'neighborhood', 'latitude', 'longitude', 'escrow_reference',
])]
class ServiceJob extends Model
{
    use HasStateMachine;

    protected $attributes = ['state' => 'scheduled', 'duration_minutes' => 60, 'payment_method' => 'electronic'];

    protected static function booted(): void
    {
        // Fim previsto derivado do início e da duração (usado em conflitos de horário e nas regras automáticas)
        static::saving(function (self $session) {
            if ($session->ends_at === null || $session->isDirty(['scheduled_at', 'duration_minutes'])) {
                $session->ends_at = $session->scheduled_at->copy()->addMinutes($session->duration_minutes);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state' => SessionState::class,
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'awaiting_since' => 'datetime',
            'no_show_contest_until' => 'datetime',
            'cash_confirmed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /**
     * @return HasMany<SessionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SessionEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * @return HasOne<EscrowHold, $this>
     */
    public function escrowHold(): HasOne
    {
        return $this->hasOne(EscrowHold::class);
    }

    /**
     * @return MorphMany<LedgerTransaction, $this>
     */
    public function ledgerTransactions(): MorphMany
    {
        return $this->morphMany(LedgerTransaction::class, 'reference');
    }

    public function isCash(): bool
    {
        return $this->payment_method === 'cash';
    }

    public function involves(User $user): bool
    {
        return $this->client_id === $user->id || $this->provider->user_id === $user->id;
    }
}
