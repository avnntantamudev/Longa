<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\AuthSession;
use App\Modules\Auth\Support\TokenPair;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sessões autenticadas por dispositivo.
 *
 *  - Web (PWA): cookie de sessão do Laravel (Sanctum SPA); o id da AuthSession fica na sessão.
 *  - API (Flutter): token de acesso curto (ability "access") + token de refresh (ability "refresh"),
 *    rodados em cada refresh. Os dois ficam ligados à mesma AuthSession.
 *
 * Revogar uma AuthSession termina essa sessão em qualquer dispositivo.
 */
class AuthSessionService
{
    public const SESSION_KEY = 'auth_session_id';

    public const ABILITY_ACCESS = 'access';

    public const ABILITY_REFRESH = 'refresh';

    public function startWeb(Request $request, User $user, bool $remember = true): AuthSession
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $session = AuthSession::query()->create([
            'user_id' => $user->id,
            'type' => AuthSession::TYPE_WEB,
            'device_name' => $this->deviceNameFrom($request),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'last_active_at' => now(),
        ]);

        $request->session()->put(self::SESSION_KEY, $session->id);

        return $session;
    }

    public function startApi(Request $request, User $user, ?string $deviceName): TokenPair
    {
        return DB::transaction(function () use ($request, $user, $deviceName) {
            $session = AuthSession::query()->create([
                'user_id' => $user->id,
                'type' => AuthSession::TYPE_API,
                'device_name' => Str::limit($deviceName ?: $this->deviceNameFrom($request), 120, ''),
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'last_active_at' => now(),
            ]);

            return $this->issueTokens($user, $session);
        });
    }

    /**
     * Troca um token de refresh válido por um novo par (o par antigo deixa de funcionar).
     */
    public function refresh(string $plainRefreshToken): TokenPair
    {
        $token = PersonalAccessToken::findToken($plainRefreshToken);

        $invalid = new ApiException('refresh_token_invalid', 'A sessão expirou. Inicie sessão novamente.', 401);

        if ($token === null || ! $token->can(self::ABILITY_REFRESH) || ($token->expires_at !== null && $token->expires_at->isPast())) {
            throw $invalid;
        }

        $session = AuthSession::query()->where('refresh_token_id', $token->id)->whereNull('revoked_at')->first();
        $user = $token->tokenable;

        if ($session === null || ! $user instanceof User || $user->isSuspended()) {
            throw $invalid;
        }

        return DB::transaction(function () use ($token, $session, $user) {
            PersonalAccessToken::query()->whereKey(array_filter([$session->access_token_id, $token->id]))->delete();

            return $this->issueTokens($user, $session);
        });
    }

    public function revoke(AuthSession $session): void
    {
        DB::transaction(function () use ($session) {
            PersonalAccessToken::query()->whereKey(array_filter([$session->access_token_id, $session->refresh_token_id]))->delete();
            $session->forceFill(['revoked_at' => now()])->save();
        });
    }

    /**
     * Termina todas as sessões do utilizador (opcionalmente mantendo a atual).
     */
    public function revokeAll(User $user, ?AuthSession $except = null): int
    {
        $sessions = AuthSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get();

        $sessions->each(fn (AuthSession $s) => $this->revoke($s));

        return $sessions->count();
    }

    /**
     * Sessão do pedido atual (pelo token de acesso ou pela sessão web).
     */
    public function current(Request $request): ?AuthSession
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return AuthSession::query()->where('access_token_id', $token->id)->first();
        }

        if ($request->hasSession() && ($id = $request->session()->get(self::SESSION_KEY))) {
            return AuthSession::query()->find($id);
        }

        return null;
    }

    private function issueTokens(User $user, AuthSession $session): TokenPair
    {
        $accessTtl = (int) config('longa.auth.access_token_ttl');
        $refreshTtl = (int) config('longa.auth.refresh_token_ttl');

        $access = $user->createToken($session->device_name ?? 'api', [self::ABILITY_ACCESS], now()->addMinutes($accessTtl));
        $refresh = $user->createToken(($session->device_name ?? 'api').' (refresh)', [self::ABILITY_REFRESH], now()->addMinutes($refreshTtl));

        $session->forceFill([
            'access_token_id' => $access->accessToken->id,
            'refresh_token_id' => $refresh->accessToken->id,
            'last_active_at' => now(),
        ])->save();

        return new TokenPair($access->plainTextToken, $accessTtl * 60, $refresh->plainTextToken, $refreshTtl * 60, $session->id);
    }

    public function deviceNameFrom(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Dispositivo',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "{$browser} · {$os}" : $os;
    }
}
