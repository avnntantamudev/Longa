<?php

declare(strict_types=1);

namespace App\Modules\Verification\Models;

use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento que uma categoria exige ao técnico (ex.: certificado profissional de eletricista), revisto pela
 * equipa. O BI e a foto continuam na verificação de identidade (Verification).
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property int|null $category_id
 * @property string $type
 * @property string $file_path
 * @property string $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 */
#[Fillable(['provider_profile_id', 'category_id', 'type', 'file_path', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason'])]
class VerificationDocument extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
