<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Disputa sobre um serviço. Só o admin a resolve; toda a decisão financeira gera uma transação no ledger.
 *
 * @property int $id
 * @property int $service_job_id
 * @property int $opened_by
 * @property string $reason
 * @property string $description
 * @property string $status
 * @property string|null $resolution
 * @property int|null $provider_share
 * @property string|null $resolution_note
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ServiceJob $session
 * @property-read User $opener
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DisputeAttachment> $attachments
 */
#[Fillable(['service_job_id', 'opened_by', 'reason', 'description'])]
class Dispute extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const REASONS = ['lesson_not_happened', 'quality', 'no_show_contest', 'other'];

    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return HasMany<DisputeAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(DisputeAttachment::class);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'lesson_not_happened' => 'O serviço não aconteceu',
            'quality' => 'Problema com o serviço',
            'no_show_contest' => 'Contesta a falta reportada',
            default => 'Outro motivo',
        };
    }
}
