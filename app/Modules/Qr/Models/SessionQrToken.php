<?php

declare(strict_types=1);

namespace App\Modules\Qr\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Token de confirmação de um serviço (o valor em claro nunca é guardado).
 *
 * @property int $id
 * @property int $service_job_id
 * @property int $provider_profile_id
 * @property string $batch_id
 * @property string $token_hash
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property int|null $used_by
 * @property int $attempts
 */
#[Fillable(['service_job_id', 'provider_profile_id', 'batch_id', 'token_hash', 'code_hash', 'valid_from', 'expires_at'])]
#[Hidden(['token_hash', 'code_hash'])]
class SessionQrToken extends Model
{
    public const UPDATED_AT = null;

    public const ACTIVE = 'active';

    public const USED = 'used';

    public const REVOKED = 'revoked';

    protected $attributes = ['status' => self::ACTIVE, 'attempts' => 0];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
