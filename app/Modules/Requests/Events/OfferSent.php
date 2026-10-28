<?php

declare(strict_types=1);

namespace App\Modules\Requests\Events;

use App\Modules\Requests\Http\Resources\ProviderOfferResource;
use App\Modules\Requests\Models\RequestOffer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * TRIM TRIM: nova oferta para um formador (canal privado do formador).
 */
class OfferSent implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'offer.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['offer' => (new ProviderOfferResource($this->offer->loadMissing('request.category')))->resolve()];
    }
}
