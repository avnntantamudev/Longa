<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Database\Factories\TrainingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pedido de aula publicado por um aluno.
 *
 * @property int $id
 * @property int $student_id
 * @property int $skill_id
 * @property string $title
 * @property string|null $description
 * @property int $amount
 * @property int $commission
 * @property int $trainer_net
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
 * @property int|null $assigned_trainer_profile_id
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $republished_from_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $student
 * @property-read Skill $skill
 * @property-read TrainingSession|null $session
 */
#[Fillable([
    'student_id', 'skill_id', 'title', 'description', 'amount', 'commission', 'trainer_net', 'scheduled_at', 'duration_minutes',
    'payment_method', 'modality',
    'province', 'municipality', 'neighborhood', 'latitude', 'longitude', 'republished_from_id',
])]
#[Hidden(['location'])]
#[UseFactory(TrainingRequestFactory::class)]
class TrainingRequest extends Model
{
    /** @use HasFactory<TrainingRequestFactory> */
    use HasFactory, HasStateMachine;

    protected $attributes = ['state' => 'draft', 'wave' => 0, 'duration_minutes' => 60, 'payment_method' => 'electronic'];

    protected function casts(): array
    {
        return [
            'state' => RequestState::class,
            'scheduled_at' => 'datetime',
            'expires_at' => 'datetime',
            'next_wave_at' => 'datetime',
            'matched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'amount' => 'integer',
            'commission' => 'integer',
            'trainer_net' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @return BelongsTo<TrainerProfile, $this>
     */
    public function assignedTrainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'assigned_trainer_profile_id');
    }

    /**
     * @return HasMany<RequestOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(RequestOffer::class);
    }

    /**
     * @return HasOne<TrainingSession, $this>
     */
    public function session(): HasOne
    {
        return $this->hasOne(TrainingSession::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function republishedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'republished_from_id');
    }
}
