<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Models;

use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sanção gradual por avaliações persistentemente más: aviso, suspensão (com fim) ou remoção (revista pela equipa).
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property string $level
 * @property string $status
 * @property float $average
 * @property int $ratings_count
 * @property string $reason
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property int|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ProviderProfile $provider
 * @property-read User|null $decider
 */
#[Fillable(['provider_profile_id', 'level', 'status', 'average', 'ratings_count', 'reason', 'ends_at'])]
class ProviderSanction extends Model
{
    public const WARNING = 'warning';

    public const SUSPENSION = 'suspension';

    public const REMOVAL = 'removal';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const PENDING_REVIEW = 'pending_review';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['average' => 'float', 'ratings_count' => 'integer', 'ends_at' => 'datetime', 'decided_at' => 'datetime'];
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
        return match ($this->level) {
            self::WARNING => 'Aviso',
            self::SUSPENSION => 'Suspensão',
            default => 'Remoção',
        };
    }
}
