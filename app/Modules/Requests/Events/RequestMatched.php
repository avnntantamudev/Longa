<?php

declare(strict_types=1);

namespace App\Modules\Requests\Events;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Models\ServiceJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Um formador aceitou: avisa o aluno (canal do pedido) e os formadores que perderam a oferta.
 */
class RequestMatched implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    /**
     * @param  list<int>  $loserUserIds  formadores com oferta aberta que ficaram sem a aula
     */
    public function __construct(
        public readonly ServiceRequest $request,
        public readonly ServiceJob $session,
        public readonly array $loserUserIds = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('request.'.$this->request->id),
            ...array_map(fn (int $id) => new PrivateChannel('user.'.$id), $this->loserUserIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'request.matched';
    }

    /**
     * Só identificadores: cada cliente pede os detalhes que pode ver à API.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['request_id' => $this->request->id, 'session_id' => $this->session->id, 'state' => $this->request->state->value];
    }
}
