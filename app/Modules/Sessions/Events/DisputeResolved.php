<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Events;

use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\TrainingSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disputa decidida pelo admin (canal privado da aula: aluno e formador).
 */
class DisputeResolved implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly TrainingSession $session, public readonly Dispute $dispute) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('session.'.$this->session->id);
    }

    public function broadcastAs(): string
    {
        return 'session.dispute_resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->id, 'state' => $this->session->state->value, 'resolution' => $this->dispute->resolution];
    }
}
