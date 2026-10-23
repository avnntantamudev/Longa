<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem do chat: texto e/ou anexo PDF (disco privado, acesso por link assinado temporário).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string|null $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property int|null $attachment_size
 * @property string|null $client_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Conversation $conversation
 * @property-read User $sender
 */
#[Fillable(['conversation_id', 'sender_id', 'body', 'attachment_path', 'attachment_name', 'attachment_size', 'client_id'])]
#[Hidden(['attachment_path'])]
class Message extends Model
{
    public const UPDATED_AT = null;

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

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }
}
