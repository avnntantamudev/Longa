<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Actions\AuthenticateWithOtp;
use App\Modules\Auth\Actions\RequestOtp;
use App\Modules\Auth\Http\Requests\RequestOtpRequest;
use App\Modules\Auth\Http\Requests\VerifyOtpRequest;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\PinService;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Login por OTP para a API (app Flutter). A PWA usa as rotas web equivalentes (sessão por cookie).
 *
 * @tags Autenticação
 */
class OtpController extends Controller
{
    /**
     * Pedir código OTP.
     *
     * Responde sempre 202 com a mesma mensagem, exista ou não conta para o telefone.
     * O utilizador escolhe o canal: SMS ou WhatsApp (se o WhatsApp falhar, o código segue por SMS).
     *
     * @unauthenticated
     */
    public function request(RequestOtpRequest $request, RequestOtp $action): JsonResponse
    {
        $channel = $request->channel();
        $action->handle($request->string('phone')->toString(), $request->ip(), $channel);

        return response()->json([
            'message' => "Se o número estiver correto, vai receber um código por {$channel->label()} dentro de instantes.",
            'channel' => $channel->value,
            'expires_in' => (int) config('longa.otp.ttl'),
            'resend_in' => (int) config('longa.otp.resend_cooldown'),
        ], 202);
    }

    /**
     * Validar código e obter tokens.
     *
     * Cria a conta se o telefone ainda não estiver registado (`created: true`).
     *
     * @unauthenticated
     */
    public function verify(VerifyOtpRequest $request, AuthenticateWithOtp $action, AuthSessionService $sessions, PinService $pins): JsonResponse
    {
        ['user' => $user, 'created' => $created] = $action->handle(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
        );

        $tokens = $sessions->startApi($request, $user, $request->input('device_name'));
        // Este aparelho passa a entrar só com o PIN; o código recente permite criar/repor o PIN
        $device = $pins->trust($user, $request, $request->input('device_name'));
        Cache::put("otp-fresh:{$tokens->sessionId}", $device['device']->id, 600);

        return response()->json([
            ...$tokens->toArray(),
            'device_token' => $device['token'],
            'pin_set' => $user->pin_hash !== null,
            'created' => $created,
            'user' => new UserResource($user),
        ], $created ? 201 : 200);
    }
}
