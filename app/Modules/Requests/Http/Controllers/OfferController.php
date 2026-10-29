<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Actions\AcceptOffer;
use App\Modules\Requests\Actions\RespondToOffer;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Http\Resources\ProviderOfferResource;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Ofertas TRIM TRIM do técnico.
 *
 * @tags TRIM TRIM (técnico)
 */
class OfferController extends Controller
{
    /**
     * Ofertas em aberto.
     *
     * Só as que ainda estão dentro do prazo, mais recentes primeiro.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $offers = RequestOffer::query()
            ->where('provider_profile_id', $this->provider($request)->providerProfile?->id)
            ->whereIn('state', OfferState::open())
            ->where('expires_at', '>', now())
            ->with(['request.category'])
            ->latest('sent_at')
            ->get();

        return ProviderOfferResource::collection($offers);
    }

    /**
     * Ver oferta.
     */
    public function show(RequestOffer $offer): ProviderOfferResource
    {
        Gate::authorize('respond', $offer);

        return new ProviderOfferResource($offer->load(['request.category']));
    }

    /**
     * Marcar oferta como vista.
     */
    public function view(RequestOffer $offer, RespondToOffer $action): ProviderOfferResource
    {
        Gate::authorize('respond', $offer);

        return new ProviderOfferResource($action->view($offer)->load(['request.category']));
    }

    /**
     * Aceitar oferta.
     *
     * Atómico: só um técnico fica com o serviço. Repetir o pedido depois de ganhar devolve o mesmo serviço.
     * Erros: `request_already_matched` (409, outro técnico aceitou primeiro), `offer_expired` (410),
     * `request_cancelled` (410), `request_closed` (410).
     */
    public function accept(RequestOffer $offer, AcceptOffer $action): JsonResponse
    {
        Gate::authorize('respond', $offer);

        $session = $action->handle($offer)->load(['provider.user', 'client', 'category', 'conversation']);

        return (new SessionResource($session))->response()->setStatusCode(200);
    }

    /**
     * Recusar oferta.
     */
    public function decline(RequestOffer $offer, RespondToOffer $action): ProviderOfferResource
    {
        Gate::authorize('respond', $offer);

        return new ProviderOfferResource($action->decline($offer)->load(['request.category']));
    }

    private function provider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
