<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use App\Modules\Sessions\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'sender' => ['id' => $this->sender_id, 'name' => $this->sender->name],
            'body' => $this->body,
            // O ficheiro em si só por link assinado temporário (GET /messages/{id}/attachment)
            'attachment' => $this->hasAttachment() ? ['name' => $this->attachment_name, 'size' => $this->attachment_size] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
