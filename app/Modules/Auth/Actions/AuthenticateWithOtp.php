<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Services\OtpService;
use App\Modules\Auth\Support\PhoneNumber;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;

/**
 * Valida o código OTP e devolve o utilizador. Um telefone sem conta cria uma conta nova
 * (registo e login são o mesmo fluxo); o perfil é escolhido depois, no onboarding.
 */
class AuthenticateWithOtp
{
    public function __construct(private readonly OtpService $otp, private readonly AuditLogger $audit) {}

    /**
     * @return array{user: User, created: bool}
     */
    public function handle(string $rawPhone, string $code): array
    {
        $phone = PhoneNumber::parse($rawPhone)
            ?? throw new ApiException('otp_invalid', 'Código inválido ou expirado.', 422);

        try {
            $this->otp->verify($phone->e164, $code);
        } catch (ApiException $e) {
            $this->audit->record('auth.login_failed', null, ['phone' => $phone->masked(), 'reason' => $e->errorCode]);

            throw $e;
        }

        $user = User::withTrashed()->where('phone', $phone->e164)->first();
        $created = false;

        if ($user?->trashed()) {
            // Conta apagada: não é reativada automaticamente
            throw new ApiException('account_unavailable', 'Esta conta não está disponível. Contacte o suporte.', 403);
        }

        if ($user === null) {
            $user = User::query()->create(['phone' => $phone->e164, 'phone_verified_at' => now()]);
            $created = true;
        }

        if ($user->isSuspended()) {
            $this->audit->record('auth.login_blocked', $user, ['reason' => 'suspended'], $user->id);

            throw new ApiException('account_suspended', 'A sua conta está suspensa. Contacte o suporte.', 403);
        }

        $user->forceFill(['last_login_at' => now(), 'phone_verified_at' => $user->phone_verified_at ?? now()])->save();

        $this->audit->record($created ? 'auth.registered' : 'auth.login_succeeded', $user, [], $user->id);

        return ['user' => $user, 'created' => $created];
    }
}
