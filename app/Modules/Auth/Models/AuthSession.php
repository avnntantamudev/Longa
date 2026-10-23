<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sessão autenticada num dispositivo (web com cookie ou API com tokens).
 *
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property string|null $device_name
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $access_token_id
 * @property int|null $refresh_token_id
 * @property \Illuminate\Support\Carbon|null $last_active_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['user_id', 'type', 'device_name', 'ip', 'user_agent', 'access_token_id', 'refresh_token_id', 'last_active_at', 'revoked_at'])]
class AuthSession extends Model
{
    use HasUuids;

    public const TYPE_WEB = 'web';

    public const TYPE_API = 'api';

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
