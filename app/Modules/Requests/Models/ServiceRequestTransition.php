<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Histórico de estados do pedido (só acréscimos): de, para, quem e quando.
 *
 * @property int $id
 * @property int $service_request_id
 * @property RequestState|null $from_state
 * @property RequestState $to_state
 * @property int|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $actor
 */
#[Fillable(['service_request_id', 'from_state', 'to_state', 'actor_id', 'reason', 'metadata'])]
class ServiceRequestTransition extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_state' => RequestState::class,
            'to_state' => RequestState::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico do pedido só aceita novas linhas.'));
        static::deleting(fn () => throw new LogicException('O histórico do pedido só aceita novas linhas.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
