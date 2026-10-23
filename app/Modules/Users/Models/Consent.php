<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registo de consentimento (termos, privacidade, localização, marketing) com versão, data e IP.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $version
 * @property \Illuminate\Support\Carbon $accepted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
#[Fillable(['type', 'version', 'accepted_at', 'revoked_at', 'ip', 'user_agent'])]
class Consent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
