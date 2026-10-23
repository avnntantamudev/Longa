<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Models\AuthSession;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Depois de "auth:sanctum": garante que a sessão do dispositivo não foi terminada
 * (logout, "terminar todas as sessões", suspensão) e que um token de refresh não é usado como acesso.
 */
class EnsureAuthSessionIsActive
{
    /** Atualiza last_active_at no máximo a cada 5 minutos */
    private const TOUCH_EVERY_SECONDS = 300;

    public function __construct(private readonly AuthSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can(AuthSessionService::ABILITY_ACCESS)) {
            throw new AuthenticationException;
        }

        $session = $this->sessions->current($request);

        if ($session === null || $session->isRevoked() || $user->isSuspended()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            throw new AuthenticationException;
        }

        $this->touch($session);

        return $next($request);
    }

    private function touch(AuthSession $session): void
    {
        if ($session->last_active_at === null || $session->last_active_at->diffInSeconds(now()) > self::TOUCH_EVERY_SECONDS) {
            $session->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
