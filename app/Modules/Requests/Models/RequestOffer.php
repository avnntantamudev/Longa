<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Users\Models\TrainerProfile;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta TRIM TRIM enviada a um formador. O prazo (`expires_at`) é decidido pelo servidor.
 *
 * @property int $id
 * @property int $training_request_id
 * @property int $trainer_profile_id
 * @property int $wave
 * @property int $rank
 * @property int $distance_m
 * @property int $score_snapshot
 * @property OfferState $state
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon|null $viewed_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property-read TrainingRequest $request
 * @property-read TrainerProfile $trainer
 */
#[Fillable(['training_request_id', 'trainer_profile_id', 'wave', 'rank', 'distance_m', 'score_snapshot', 'sent_at', 'expires_at'])]
#[Hidden(['accepted_request_id'])]
class RequestOffer extends Model
{
    use HasStateMachine;

    protected $attributes = ['state' => 'sent'];

    protected function casts(): array
    {
        return [
            'state' => OfferState::class,
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
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
     * @return BelongsTo<TrainerProfile, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, OfferState::open(), true) && $this->expires_at->isFuture();
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
