<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Events;

use App\Modules\Sessions\Models\ServiceJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Serviço confirmado. (canal privado do serviço: cliente e técnico).
 */
class SessionConfirmed implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly ServiceJob $session) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('session.'.$this->session->id);
    }

    public function broadcastAs(): string
    {
        return 'session.confirmed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->id, 'state' => $this->session->state->value];
    }
}
