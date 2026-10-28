<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Support\Api\ApiException;
use App\Support\StateMachine\InvalidTransition;

/**
 * Técnico abre a oferta (viewed) ou recusa-a (declined, conta para o score).
 */
class RespondToOffer
{
    public function __construct(private readonly ScoreRecorder $score) {}

    public function view(RequestOffer $offer): RequestOffer
    {
        if ($offer->state === OfferState::Sent && $offer->expires_at->isFuture()) {
            try {
                $offer->transitionTo(OfferState::Viewed, ['viewed_at' => now()]);
            } catch (InvalidTransition) {
                $offer->refresh();
            }
        }

        return $offer;
    }

    public function decline(RequestOffer $offer): RequestOffer
    {
        if ($offer->state === OfferState::Declined) {
            return $offer;
        }

        if (! $offer->isOpen()) {
            throw new ApiException('offer_closed', 'Esta oferta já não está disponível.', 410);
        }

        try {
            $offer->transitionTo(OfferState::Declined, ['responded_at' => now()]);
        } catch (InvalidTransition) {
            throw new ApiException('offer_closed', 'Esta oferta já não está disponível.', 410);
        }

        $this->score->record($offer->provider_profile_id, ScoreEvent::OFFER_DECLINED, $offer, [
            'seconds_to_respond' => (int) $offer->sent_at->diffInSeconds(now()),
        ]);

        return $offer;
    }
}
