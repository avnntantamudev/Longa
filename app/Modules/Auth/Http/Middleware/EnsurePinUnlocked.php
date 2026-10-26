<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessão web (PWA e backoffice) com PIN:
 *  - sem PIN criado → criar PIN;
 *  - mais de 1 h sem uso (ou 8 h desde o último PIN, para administradores) → desbloquear com o PIN.
 *
 * Páginas redirecionam; pedidos à API com o cookie da PWA recebem 423 (a app mostra o ecrã certo).
 * Tokens Bearer (app Flutter) não passam por aqui: a app bloqueia-se localmente e usa /auth/pin/verify.
 */
class EnsurePinUnlocked
{
    public const UNLOCKED_AT = 'pin_unlocked_at';

    public const LAST_SEEN = 'pin_last_seen';

    /** Rotas sempre acessíveis (criar/desbloquear PIN, sair, tempo real) */
    private const EXEMPT = ['pin.setup', 'pin.setup.store', 'pin.unlock', 'pin.unlock.store', 'logout', 'api.auth.logout', 'api.auth.pin.setup', 'api.auth.pin.verify', 'api.me', 'photos.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession() || $request->bearerToken() !== null || in_array($request->route()?->getName(), self::EXEMPT, true)) {
            return $next($request);
        }

        if ($user->pin_hash === null) {
            return $this->deny($request, 'pin_setup_required', 'Crie o seu PIN para continuar.', route('pin.setup'));
        }

        $session = $request->session();
        $unlockedAt = $session->get(self::UNLOCKED_AT);
        $lastSeen = $session->get(self::LAST_SEEN);
        $idle = (int) config('longa.auth.pin_idle_minutes');
        $adminHours = (int) config('longa.auth.admin_pin_hours');

        $locked = $unlockedAt === null
            || $lastSeen === null
            || Carbon::createFromTimestamp((int) $lastSeen)->addMinutes($idle)->isPast()
            || ($user->hasRole(Role::Admin->value) && Carbon::createFromTimestamp((int) $unlockedAt)->addHours($adminHours)->isPast());

        if ($locked) {
            return $this->deny($request, 'pin_unlock_required', 'Introduza o seu PIN para continuar.', route('pin.unlock'));
        }

        $session->put(self::LAST_SEEN, now()->getTimestamp());

        return $next($request);
    }

    private function deny(Request $request, string $code, string $message, string $redirect): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            throw new ApiException($code, $message, 423, ['redirect' => $redirect]);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return $request->header('X-Inertia') ? Inertia::location($redirect) : redirect($redirect);
    }
}
