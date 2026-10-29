<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\StateMachine\HasStateMachine;
use App\Support\StateMachine\StateEnum;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

/**
 * Pedido de serviço publicado por um cliente.
 *
 * @property int $id
 * @property int $client_id
 * @property int $category_id
 * @property PricingMode $pricing_mode
 * @property string $title
 * @property string|null $description
 * @property int $amount
 * @property bool $scheduled
 * @property int $commission
 * @property int $provider_net
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property int $duration_minutes
 * @property string $payment_method
 * @property string $modality
 * @property string $province
 * @property string $municipality
 * @property string $neighborhood
 * @property float $latitude
 * @property float $longitude
 * @property RequestState $state
 * @property int $wave
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $next_wave_at
 * @property \Illuminate\Support\Carbon|null $matched_at
 * @property int|null $assigned_provider_profile_id
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $republished_from_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $client
 * @property-read Category $category
 * @property-read ServiceJob|null $session
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceRequestTransition> $transitions
 */
#[Fillable([
    'client_id', 'category_id', 'pricing_mode', 'title', 'description', 'amount', 'commission', 'provider_net', 'scheduled_at', 'duration_minutes',
    'payment_method', 'modality',
    'province', 'municipality', 'neighborhood', 'latitude', 'longitude', 'republished_from_id',
])]
#[Hidden(['location'])]
#[UseFactory(ServiceRequestFactory::class)]
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory, HasStateMachine;

    protected $attributes = ['state' => 'created', 'pricing_mode' => 'fixed', 'wave' => 0, 'duration_minutes' => 60, 'payment_method' => 'electronic'];

    /** Quem faz a próxima transição e porquê (por omissão: o utilizador autenticado; null = sistema) */
    private ?int $transitionActorId = null;

    private ?string $transitionReason = null;

    private bool $transitionActorSet = false;

    protected static function booted(): void
    {
        static::created(fn (self $request) => $request->transitions()->create([
            'from_state' => null,
            'to_state' => $request->state,
            'actor_id' => $request->client_id,
        ]));
    }

    protected function casts(): array
    {
        return [
            'state' => RequestState::class,
            'pricing_mode' => PricingMode::class,
            'scheduled_at' => 'datetime',
            'expires_at' => 'datetime',
            'next_wave_at' => 'datetime',
            'matched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'amount' => 'integer',
            'commission' => 'integer',
            'scheduled' => 'boolean',
            'provider_net' => 'integer',
        ];
    }

    /**
     * Indica quem faz a próxima transição (null = sistema) e o motivo: $request->by($user, 'motivo')->transitionTo(...).
     */
    public function by(User|int|null $actor, ?string $reason = null): static
    {
        $this->transitionActorId = $actor instanceof User ? $actor->id : $actor;
        $this->transitionReason = $reason;
        $this->transitionActorSet = true;

        return $this;
    }

    /**
     * Diagnóstico e orçamento só existem nos serviços por orçamento; nesses, não se salta para a execução.
     */
    protected function transitionAllowed(StateEnum $from, StateEnum $to): bool
    {
        $quote = $this->pricing_mode === PricingMode::Quote;

        if (in_array($to, RequestState::quoteOnly(), true)) {
            return $quote;
        }

        return ! ($quote && $from === RequestState::OnTheWay && $to === RequestState::InProgress);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function afterTransition(StateEnum $from, StateEnum $to, array $attributes): void
    {
        $this->transitions()->create([
            'from_state' => $from,
            'to_state' => $to,
            'actor_id' => $this->transitionActorSet ? $this->transitionActorId : Auth::id(),
            'reason' => $this->transitionReason ?? (isset($attributes['cancel_reason']) ? (string) $attributes['cancel_reason'] : null),
        ]);

        $this->transitionActorId = null;
        $this->transitionReason = null;
        $this->transitionActorSet = false;
    }

    /**
     * @return HasMany<ServiceRequestTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(ServiceRequestTransition::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'assigned_provider_profile_id');
    }

    /**
     * @return HasMany<RequestOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(RequestOffer::class);
    }

    /**
     * @return HasOne<ServiceJob, $this>
     */
    public function session(): HasOne
    {
        return $this->hasOne(ServiceJob::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function republishedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'republished_from_id');
    }
}
