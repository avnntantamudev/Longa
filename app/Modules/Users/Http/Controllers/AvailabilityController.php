<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Perfis
 */
class AvailabilityController extends Controller
{
    /**
     * Técnico: Disponível / Indisponível para receber pedidos TRIM TRIM.
     *
     * Só técnicos verificados e com o perfil completo podem ficar disponíveis.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']], [], ['is_available' => 'disponibilidade']);

        /** @var User $user */
        $user = $request->user();
        $profile = $user->providerProfile ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de técnico.', 409);

        if ($data['is_available'] && (! $profile->isVerified() || $profile->onboarding_completed_at === null)) {
            throw new ApiException('provider_not_ready', 'Complete o perfil e a verificação do BISCA para receber pedidos.', 409);
        }

        $profile->is_available = (bool) $data['is_available'];
        $profile->save();

        return response()->json(['data' => ['is_available' => $profile->is_available]]);
    }
}
