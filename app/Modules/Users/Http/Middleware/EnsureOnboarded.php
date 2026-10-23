<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Middleware;

use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige o registo concluído (nome, perfil e consentimentos). Na web redireciona para /onboarding;
 * na API responde 409 "onboarding_required".
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->onboarded_at === null) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                throw new ApiException('onboarding_required', 'Conclua o registo para continuar.', 409);
            }

            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
