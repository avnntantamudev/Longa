<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\PinService;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PIN de 6 dígitos e aparelhos de confiança.
 *
 * O token do aparelho chega no corpo (`device_token`, app) ou no cabeçalho `X-Device-Token`; na PWA vem
 * do cookie protegido `longa_device`.
 *
 * @tags Autenticação
 */
class PinController extends Controller
{
    public const COOKIE = 'longa_device';

    /**
     * Entrar com o PIN num aparelho de confiança (sem código SMS).
     *
     * Erros: `pin_invalid` (422), `pin_locked` (429), `device_not_trusted` (401: entrar com o código).
     *
     * @unauthenticated
     */
    public function login(Request $request, PinService $pins, AuthSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['device_token' => ['required', 'string', 'size:64'], 'pin' => ['required', 'digits:6'], 'device_name' => ['nullable', 'string', 'max:120']]);
        $device = $pins->findDevice($data['device_token']) ?? throw new ApiException('device_not_trusted', 'Este aparelho não está autorizado. Entre com o código SMS.', 401);

        $user = $pins->attempt($device, $data['pin']);
        $tokens = $sessions->startApi($request, $user, $data['device_name'] ?? $device->name);
        $pins->markRecent($tokens->sessionId);

        return response()->json([...$tokens->toArray(), 'user' => new UserResource($user)]);
    }

    /**
     * Criar o PIN (logo depois de entrar com o código) ou repor um PIN esquecido (código recente).
     */
    public function setup(Request $request, PinService $pins, AuthSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6', 'confirmed']], ['pin.confirmed' => 'Os dois PIN não coincidem.']);
        $user = $this->user($request);
        $session = $sessions->current($request);

        // Repor exige ter entrado com o código há pouco (quem só sabe o PIN não pode trocá-lo por aqui)
        $freshDevice = $session === null ? null : Cache::get("otp-fresh:{$session->id}");
        if ($user->pin_hash !== null && $freshDevice === null) {
            throw new ApiException('pin_exists', 'Já tem PIN. Para o trocar, use "Alterar PIN" ou entre com o código SMS.', 409);
        }

        // Ao repor, mantém este aparelho (o que acabou de entrar com o código); os outros saem
        $keep = $this->device($request, $pins) ?? (is_string($freshDevice) ? TrustedDevice::query()->find($freshDevice) : null);
        $pins->set($user, $data['pin'], $keep);
        if ($session !== null) {
            $pins->markRecent($session->id);
        }

        return response()->json(['data' => ['pin_set' => true]]);
    }

    /**
     * Alterar o PIN (com o PIN atual). Os outros aparelhos saem da conta.
     */
    public function change(Request $request, PinService $pins): JsonResponse
    {
        $data = $request->validate(['current_pin' => ['required', 'digits:6'], 'pin' => ['required', 'digits:6', 'confirmed']], ['pin.confirmed' => 'Os dois PIN não coincidem.']);
        $user = $this->user($request);
        $this->checkWithoutDevice($user, $data['current_pin'], 'current_pin');

        $pins->set($user, $data['pin'], $this->device($request, $pins));

        return response()->json(['data' => ['pin_set' => true]]);
    }

    /**
     * Confirmar o PIN antes de uma operação com dinheiro (válido 5 minutos nesta sessão).
     *
     * Erros: `pin_invalid` (422), `pin_locked` (429).
     */
    public function verify(Request $request, PinService $pins, AuthSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $user = $this->user($request);
        $device = $this->device($request, $pins);

        if ($device !== null && $device->user_id === $user->id) {
            $pins->attempt($device, $data['pin']);
        } else {
            $this->checkWithoutDevice($user, $data['pin'], 'pin');
        }

        $session = $sessions->current($request);
        if ($session !== null) {
            $pins->markRecent($session->id);
        }

        return response()->json(['data' => ['verified' => true, 'valid_for' => PinService::RECENT_SECONDS]]);
    }

    /**
     * Os meus aparelhos de confiança.
     */
    public function devices(Request $request, PinService $pins): JsonResponse
    {
        $current = $this->device($request, $pins);
        $devices = TrustedDevice::query()->where('user_id', $this->user($request)->id)->whereNull('revoked_at')->latest('last_used_at')->get();

        return response()->json(['data' => $devices->map(fn (TrustedDevice $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'last_used_at' => $d->last_used_at?->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
            'current' => $current?->id === $d->id,
        ])]);
    }

    /**
     * Remover um aparelho (passa a precisar do código SMS para entrar).
     */
    public function revokeDevice(Request $request, TrustedDevice $device, PinService $pins): JsonResponse
    {
        abort_unless($device->user_id === $this->user($request)->id, 404);
        $pins->revoke($device, 'user');

        return response()->json(null, 204);
    }

    /**
     * Sem aparelho (ex.: token API sem X-Device-Token): limite de 5 tentativas a cada 15 min por utilizador.
     */
    private function checkWithoutDevice(User $user, string $pin, string $field): void
    {
        $key = 'pin-check:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, PinService::LOCK_AFTER)) {
            throw new ApiException('pin_locked', 'Demasiadas tentativas. Tente de novo mais tarde.', 429);
        }
        if ($user->pin_hash === null || ! Hash::check($pin, $user->pin_hash)) {
            RateLimiter::hit($key, PinService::LOCK_MINUTES * 60);

            throw new ApiException('pin_invalid', 'PIN errado.', 422, [$field => ['PIN errado.']]);
        }
        RateLimiter::clear($key);
    }

    private function device(Request $request, PinService $pins): ?TrustedDevice
    {
        $token = $request->input('device_token') ?? $request->header('X-Device-Token') ?? $request->cookie(self::COOKIE);

        return $pins->findDevice(is_string($token) ? $token : null);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
