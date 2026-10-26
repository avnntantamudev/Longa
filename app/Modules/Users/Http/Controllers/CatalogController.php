<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\PublicTrainerResource;
use App\Modules\Users\Http\Resources\SkillResource;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
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
    public function skills(): AnonymousResourceCollection
    {
        return SkillResource::collection(Skill::query()->active()->orderBy('category')->orderBy('name')->get());
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
    public function trainer(TrainerProfile $trainer): PublicTrainerResource
    {
        abort_unless($trainer->isVerified() && $trainer->onboarding_completed_at !== null && ! $trainer->user->isSuspended(), 404);

        return new PublicTrainerResource($trainer->load(['skills', 'user']));
    }
}
