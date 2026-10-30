<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem do chat: texto e/ou anexo (PDF ou foto; disco privado, acesso por link assinado temporário).
 * Mensagens com contactos ou tentativas de combinar fora da plataforma ficam sinalizadas para a equipa.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string|null $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property int|null $attachment_size
 * @property string|null $client_id
 * @property string|null $attachment_mime
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property bool $flagged
 * @property list<string>|null $flag_reasons
 * @property string|null $flagged_body
 * @property string|null $flag_status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Conversation $conversation
 * @property-read User $sender
 */
#[Fillable(['conversation_id', 'sender_id', 'body', 'attachment_path', 'attachment_name', 'attachment_size', 'attachment_mime', 'client_id', 'flagged', 'flag_reasons', 'flagged_body', 'flag_status'])]
#[Hidden(['attachment_path', 'flagged_body'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    public const FLAG_PENDING = 'pending';

    public const FLAG_REVIEWED = 'reviewed';

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'flagged' => 'boolean', 'flag_reasons' => 'array', 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isPhoto(): bool
    {
        return str_starts_with((string) $this->attachment_mime, 'image/');
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }
}
