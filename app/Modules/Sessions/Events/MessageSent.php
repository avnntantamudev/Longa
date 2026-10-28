<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Events;

use App\Modules\Sessions\Http\Resources\MessageResource;
use App\Modules\Sessions\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nova mensagem no chat do serviço (canal privado dos participantes).
 * Enviado de imediato (sem fila) para o chat parecer instantâneo.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Message $message, public readonly int $sessionId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Sem "is_me": cada cliente compara sender.id com o seu próprio id
        return ['message' => (new MessageResource($this->message->loadMissing('sender')))->resolve()];
    }
}
