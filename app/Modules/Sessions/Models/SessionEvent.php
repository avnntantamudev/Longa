<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Linha do tempo da aula (só acréscimos): transições, QR, pagamentos, sinais de risco, decisões.
 *
 * @property int $id
 * @property int $service_job_id
 * @property string $type
 * @property string|null $from_state
 * @property string|null $to_state
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $actor
 */
#[Fillable(['service_job_id', 'type', 'from_state', 'to_state', 'actor_id', 'metadata'])]
class SessionEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A linha do tempo da aula só aceita novos eventos.'));
        static::deleting(fn () => throw new LogicException('A linha do tempo da aula só aceita novos eventos.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
