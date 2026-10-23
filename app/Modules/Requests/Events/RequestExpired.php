<?php

declare(strict_types=1);

namespace App\Modules\Requests\Events;

use App\Modules\Requests\Models\TrainingRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O pedido terminou sem formador (sem candidatos ou sem aceitação a tempo).
 */
class RequestExpired implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    /**
     * @param  'no_trainers'|'timeout'  $reason
     */
    public function __construct(public readonly TrainingRequest $request, public readonly string $reason) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('request.'.$this->request->id);
    }

    public function broadcastAs(): string
    {
        return 'request.expired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['request_id' => $this->request->id, 'state' => $this->request->state->value, 'reason' => $this->reason];
    }
}
