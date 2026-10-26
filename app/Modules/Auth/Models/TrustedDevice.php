<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aparelho onde o utilizador já entrou com o código (OTP) e onde pode entrar só com o PIN.
 *
 * @property string $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $name
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int $failed_attempts
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'token_hash', 'name', 'ip', 'user_agent'])]
#[Hidden(['token_hash'])]
class TrustedDevice extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['locked_until' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'failed_attempts' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until?->isFuture() ?? false;
    }
}
