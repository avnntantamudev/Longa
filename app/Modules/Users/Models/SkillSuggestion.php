<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Área escrita pelo formador que não existe na lista. Fica em análise até o admin a aprovar
 * (cria uma área nova ou junta-a a uma existente) ou rejeitar. Não entra na lista sem aprovação.
 *
 * @property int $id
 * @property int $trainer_profile_id
 * @property string $name
 * @property string $normalized
 * @property string $status
 * @property int|null $skill_id
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read TrainerProfile $trainer
 * @property-read Skill|null $skill
 */
#[Fillable(['trainer_profile_id', 'name', 'normalized'])]
class SkillSuggestion extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
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
}
