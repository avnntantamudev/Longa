<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\CategoryFormResource;
use App\Modules\Users\Http\Resources\CategoryNodeResource;
use App\Modules\Users\Http\Resources\CategoryResource;
use App\Modules\Users\Http\Resources\PublicProviderResource;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Services\CategoryTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dados públicos de apoio: áreas de serviço, zonas e perfil público de técnicos.
 *
 * @tags Catálogo
 */
class CatalogController extends Controller
{
    /**
     * Categorias que se podem pedir (folhas da árvore), com o caminho e o grupo para listas.
     *
     * @unauthenticated
     */
    public function categories(CategoryTree $tree): AnonymousResourceCollection
    {
        return CategoryResource::collection($tree->leaves());
    }

    /**
     * Árvore completa de categorias ativas.
     *
     * @unauthenticated
     */
    public function categoryTree(CategoryTree $tree): AnonymousResourceCollection
    {
        return CategoryNodeResource::collection($tree->roots());
    }

    /**
     * Formulário de uma categoria: campos (incluindo os herdados), modalidades, modo de preço e preços.
     *
     * @unauthenticated
     */
    public function categoryForm(CategoryTree $tree, int $category): CategoryFormResource
    {
        $node = $tree->find($category);
        abort_if($node === null || $node->children->isNotEmpty(), 404);

        return new CategoryFormResource($node);
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
     * Perfil público de um técnico.
     *
     * Só técnicos verificados e com onboarding concluído. Nunca inclui contactos nem coordenadas.
     *
     * @unauthenticated
     */
    public function provider(ProviderProfile $provider): PublicProviderResource
    {
        abort_unless($provider->isVerified() && $provider->onboarding_completed_at !== null && ! $provider->user->isSuspended(), 404);

        return new PublicProviderResource($provider->load(['categories', 'user']));
    }
}
