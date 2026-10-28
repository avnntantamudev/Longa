<?php

declare(strict_types=1);

namespace App\Modules\Requests\Events;

use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O cliente cancelou: fecha o ecrã de procura e as ofertas abertas nos técnicos.
 */
class RequestCancelled implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    /**
     * @param  list<int>  $providerUserIds  técnicos com oferta aberta
     */
    public function __construct(public readonly ServiceRequest $request, public readonly array $providerUserIds = []) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('request.'.$this->request->id),
            ...array_map(fn (int $id) => new PrivateChannel('user.'.$id), $this->providerUserIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'request.cancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['request_id' => $this->request->id, 'state' => $this->request->state->value];
    }
}
