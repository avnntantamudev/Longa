<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Código OTP (só o hash é guardado).
 *
 * @property int $id
 * @property string $phone
 * @property string $code_hash
 * @property string $purpose
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['phone', 'code_hash', 'purpose', 'expires_at', 'ip'])]
#[Hidden(['code_hash'])]
class OtpCode extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
