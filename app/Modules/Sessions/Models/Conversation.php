<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chat da aula (aluno ↔ formador).
 *
 * @property int $id
 * @property int $service_job_id
 * @property-read ServiceJob $session
 */
#[Fillable(['service_job_id'])]
class Conversation extends Model
{
    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
