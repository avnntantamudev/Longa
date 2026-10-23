<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de teste do Reverb, emitido no canal público "longa.ping".
 * Disparar com: php artisan longa:ping
 */
class PingBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $message = 'pong') {}

    public function broadcastOn(): Channel
    {
        return new Channel('longa.ping');
    }

    public function broadcastAs(): string
    {
        return 'ping';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message, 'sent_at' => now()->toIso8601String()];
    }
}
