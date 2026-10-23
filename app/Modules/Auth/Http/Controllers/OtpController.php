<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Actions\AuthenticateWithOtp;
use App\Modules\Auth\Actions\RequestOtp;
use App\Modules\Auth\Http\Requests\RequestOtpRequest;
use App\Modules\Auth\Http\Requests\VerifyOtpRequest;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

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
     *
     * @unauthenticated
     */
    public function request(RequestOtpRequest $request, RequestOtp $action): JsonResponse
    {
        $action->handle($request->string('phone')->toString(), $request->ip());

        return response()->json([
            'message' => 'Se o número estiver correto, vai receber um código por SMS dentro de instantes.',
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
    public function verify(VerifyOtpRequest $request, AuthenticateWithOtp $action, AuthSessionService $sessions): JsonResponse
    {
        ['user' => $user, 'created' => $created] = $action->handle(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
        );

        $tokens = $sessions->startApi($request, $user, $request->input('device_name'));

        return response()->json([
            ...$tokens->toArray(),
            'created' => $created,
            'user' => new UserResource($user),
        ], $created ? 201 : 200);
    }
}
