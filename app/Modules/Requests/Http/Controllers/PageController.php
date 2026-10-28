<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Http\Resources\CategoryResource;
use App\Modules\Users\Models\Category;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia do TRIM TRIM. Os dados vivos vêm da API (/api/v1) e do Reverb.
 */
class PageController extends Controller
{
    public function createRequest(): Response
    {
        return Inertia::render('Requests/Create', [
            'categories' => CategoryResource::collection(Category::query()->active()->orderBy('area')->orderBy('name')->get())->resolve(),
            'zones' => config('locations.zones'),
            'provinces' => config('locations.provinces'),
            'minAmount' => (int) config('longa.requests.min_amount'),
            'minLeadMinutes' => (int) config('longa.requests.min_lead_minutes'),
            'commissionPercent' => app(PlatformSettings::class)->int('commission_percent'),
        ]);
    }

    public function showRequest(ServiceRequest $serviceRequest): Response
    {
        Gate::authorize('view', $serviceRequest);

        return Inertia::render('Requests/Show', ['requestId' => $serviceRequest->id]);
    }

    public function offers(): Response
    {
        return Inertia::render('Provider/Offers');
    }

    public function showOffer(RequestOffer $offer): Response
    {
        Gate::authorize('respond', $offer);

        return Inertia::render('Provider/Offer', ['offerId' => $offer->id, 'zones' => config('locations.zones')]);
    }
}
