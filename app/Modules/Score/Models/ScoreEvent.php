<?php

declare(strict_types=1);

namespace App\Modules\Score\Models;

use App\Modules\Users\Models\TrainerProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento que influencia o Score do formador. Nesta fase só é registado (points = 0);
 * o cálculo fica para a Fase 6.
 *
 * @property int $id
 * @property int $trainer_profile_id
 * @property string $type
 * @property int $points
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['trainer_profile_id', 'type', 'points', 'subject_type', 'subject_id', 'metadata'])]
class ScoreEvent extends Model
{
    public const UPDATED_AT = null;

    public const OFFER_DECLINED = 'offer_declined';

    public const OFFER_EXPIRED = 'offer_expired';

    public const LATE_CANCELLATION = 'late_cancellation';

    public const NO_SHOW = 'no_show';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * @return BelongsTo<TrainerProfile, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }
}
