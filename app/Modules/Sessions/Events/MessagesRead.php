<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A outra parte leu as mensagens até $lastMessageId (estado de leitura no chat).
 */
class MessagesRead implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly int $sessionId, public readonly int $readerId, public readonly int $lastMessageId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['reader_id' => $this->readerId, 'last_message_id' => $this->lastMessageId];
    }
}
