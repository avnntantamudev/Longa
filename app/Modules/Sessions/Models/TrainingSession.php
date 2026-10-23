<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Aula criada quando um formador aceita um pedido. Estados e regras em docs/state-machines.md.
 *
 * @property int $id
 * @property int $training_request_id
 * @property int $student_id
 * @property int $trainer_profile_id
 * @property int $skill_id
 * @property string $title
 * @property int $amount
 * @property int $commission
 * @property int $trainer_net
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
 * @property string $municipality
 * @property string $neighborhood
 * @property float $latitude
 * @property float $longitude
 * @property SessionState $state
 * @property string|null $escrow_reference
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $student
 * @property-read TrainerProfile $trainer
 * @property-read Skill $skill
 * @property-read Conversation|null $conversation
 */
#[Fillable([
    'training_request_id', 'student_id', 'trainer_profile_id', 'skill_id', 'title', 'amount', 'commission', 'trainer_net',
    'scheduled_at', 'duration_minutes', 'ends_at', 'payment_method', 'payment_due_at', 'modality', 'municipality',
    'neighborhood', 'latitude', 'longitude', 'escrow_reference',
])]
class TrainingSession extends Model
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
     * @return BelongsTo<TrainingRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TrainingRequest::class, 'training_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<TrainerProfile, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
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

    public function isCash(): bool
    {
        return $this->payment_method === 'cash';
    }

    public function involves(User $user): bool
    {
        return $this->student_id === $user->id || $this->trainer->user_id === $user->id;
    }
}
