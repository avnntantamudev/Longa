<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Models\LedgerTransaction;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Pedido de serviço: do envio pelo cliente ao pagamento, num só registo com um só estado (docs/state-machines.md).
 * Depois de um técnico aceitar, guarda também a execução (início, fim, confirmação, pagamento).
 *
 * @property int $id
 * @property int $client_id
 * @property int $category_id
 * @property PricingMode $pricing_mode
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $answers respostas do formulário da categoria
 * @property int $amount
 * @property bool $scheduled
 * @property int $commission
 * @property int $provider_net
 * @property int|null $commission_percent
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property int $duration_minutes
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property string $payment_method
 * @property string|null $payment_status
 * @property \Illuminate\Support\Carbon|null $payment_due_at
 * @property string|null $escrow_reference
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
 * @property \Illuminate\Support\Carbon|null $on_the_way_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $cash_confirmed_at
 * @property \Illuminate\Support\Carbon|null $no_show_contest_until
 * @property int|null $provider_profile_id
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property string|null $closed_reason
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $republished_from_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $client
 * @property-read Category $category
 * @property-read ProviderProfile $provider só existe depois de aceite (provider_profile_id não nulo)
 * @property-read Conversation|null $conversation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceRequestMedia> $media
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceRequestTransition> $transitions
 */
#[Fillable([
    'client_id', 'category_id', 'pricing_mode', 'title', 'description', 'answers', 'amount', 'commission', 'provider_net', 'commission_percent',
    'scheduled_at', 'duration_minutes', 'ends_at', 'payment_method', 'payment_status', 'payment_due_at', 'escrow_reference', 'modality',
    'province', 'municipality', 'neighborhood', 'latitude', 'longitude', 'republished_from_id',
])]
#[Hidden(['location'])]
#[UseFactory(ServiceRequestFactory::class)]
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory, HasStateMachine;

    /** payment_status: à espera do pagamento digital, dinheiro retido (escrow) ou prazo de pagamento vencido */
    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_HELD = 'held';

    public const PAYMENT_FAILED = 'failed';

    protected $attributes = ['state' => 'created', 'pricing_mode' => 'fixed', 'wave' => 0, 'duration_minutes' => 60, 'payment_method' => 'electronic'];

    /** Quem faz a próxima transição e porquê (por omissão: o utilizador autenticado; null = sistema) */
    private ?int $transitionActorId = null;

    private ?string $transitionReason = null;

    private bool $transitionActorSet = false;

    protected static function booted(): void
    {
        // Fim previsto derivado do início e da duração (conflitos de horário e regras automáticas)
        static::saving(function (self $request) {
            if ($request->ends_at === null || $request->isDirty(['scheduled_at', 'duration_minutes'])) {
                $request->ends_at = $request->scheduled_at->copy()->addMinutes($request->duration_minutes);
            }
        });

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
            'answers' => 'array',
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'next_wave_at' => 'datetime',
            'matched_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'on_the_way_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cash_confirmed_at' => 'datetime',
            'no_show_contest_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'amount' => 'integer',
            'commission' => 'integer',
            'commission_percent' => 'integer',
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
     * Técnico que aceitou o pedido (null enquanto ninguém aceitou).
     *
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /**
     * Fotos e vídeos do formulário.
     *
     * @return HasMany<ServiceRequestMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ServiceRequestMedia::class)->orderBy('id');
    }

    /**
     * @return HasMany<RequestOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(RequestOffer::class);
    }

    /**
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /**
     * Linha do tempo da execução (QR, pagamentos, sinais de risco, decisões), além do histórico de estados.
     *
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

    /**
     * @return BelongsTo<self, $this>
     */
    public function republishedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'republished_from_id');
    }

    /**
     * Respostas do formulário prontas a mostrar (com o rótulo do campo) e os ficheiros enviados (sem caminhos:
     * cada um abre-se por GET /api/v1/request-media/{id}).
     *
     * @return array{answers: list<array{key: string, label: string, value: mixed}>, media: list<array{id: int, field_key: string, kind: string}>}
     */
    public function formSummary(): array
    {
        $labels = [];
        foreach ($this->category->formFields() as $field) {
            $labels[$field->key] = $field->label;
        }

        $answers = [];
        foreach ($this->answers ?? [] as $key => $value) {
            $answers[] = ['key' => (string) $key, 'label' => $labels[$key] ?? (string) $key, 'value' => $value];
        }

        return [
            'answers' => $answers,
            'media' => $this->media->map(fn (ServiceRequestMedia $m) => ['id' => $m->id, 'field_key' => $m->field_key, 'kind' => $m->kind])->values()->all(),
        ];
    }

    public function isCash(): bool
    {
        return $this->payment_method === 'cash';
    }

    public function awaitingPayment(): bool
    {
        return $this->state === RequestState::Accepted && $this->payment_status === self::PAYMENT_PENDING;
    }

    public function involves(User $user): bool
    {
        return $this->client_id === $user->id || ($this->provider_profile_id !== null && $this->provider->user_id === $user->id);
    }
}
