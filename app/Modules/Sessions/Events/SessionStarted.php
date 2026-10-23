<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Events;

use App\Modules\Sessions\Models\TrainingSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O formador iniciou a aula. (canal privado da aula: aluno e formador).
 */
class SessionStarted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly TrainingSession $session) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('session.'.$this->session->id);
    }

    public function broadcastAs(): string
    {
        return 'session.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->id, 'state' => $this->session->state->value];
    }
}
