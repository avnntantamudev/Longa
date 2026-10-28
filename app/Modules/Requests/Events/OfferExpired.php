<?php

declare(strict_types=1);

namespace App\Modules\Requests\Events;

use App\Modules\Requests\Models\RequestOffer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A oferta terminou sem resposta (prazo de 60 s) ou foi perdida para outro formador / pedido terminado.
 */
class OfferExpired implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly RequestOffer $offer) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->offer->provider->user_id);
    }

    public function broadcastAs(): string
    {
        return 'offer.expired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['offer_id' => $this->offer->id, 'request_id' => $this->offer->service_request_id, 'state' => $this->offer->state->value];
    }
}
