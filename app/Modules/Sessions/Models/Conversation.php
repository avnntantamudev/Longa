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
 * @property int $training_session_id
 * @property-read TrainingSession $session
 */
#[Fillable(['training_session_id'])]
class Conversation extends Model
{
    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
