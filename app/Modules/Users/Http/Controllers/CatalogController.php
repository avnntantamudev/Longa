<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\CategoryResource;
use App\Modules\Users\Http\Resources\PublicProviderResource;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dados públicos de apoio: áreas de formação, zonas e perfil público de formadores.
 *
 * @tags Catálogo
 */
class CatalogController extends Controller
{
    /**
     * Áreas de formação.
     *
     * @unauthenticated
     */
    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()->active()->orderBy('area')->orderBy('name')->get());
    }

    /**
     * Zonas para escolha manual de local.
     *
     * @unauthenticated
     */
    /**
     * Províncias de Angola (21, divisão de 2024).
     */
    public function provinces(): JsonResponse
    {
        return response()->json(['data' => config('locations.provinces')]);
    }

    public function zones(): JsonResponse
    {
        return response()->json(['data' => config('locations.zones')]);
    }

    /**
     * Perfil público de um formador.
     *
     * Só formadores verificados e com onboarding concluído. Nunca inclui contactos nem coordenadas.
     *
     * @unauthenticated
     */
    public function provider(ProviderProfile $provider): PublicProviderResource
    {
        abort_unless($provider->isVerified() && $provider->onboarding_completed_at !== null && ! $provider->user->isSuspended(), 404);

        return new PublicProviderResource($provider->load(['categories', 'user']));
    }
}
