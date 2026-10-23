<?php

declare(strict_types=1);

namespace App\Modules\Requests\Policies;

use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Users\Models\User;

/**
 * Cada formador só vê e responde às suas próprias ofertas.
 */
class RequestOfferPolicy
{
    public function respond(User $user, RequestOffer $offer): bool
    {
        return $user->trainerProfile !== null && $offer->trainer_profile_id === $user->trainerProfile->id;
    }
}
