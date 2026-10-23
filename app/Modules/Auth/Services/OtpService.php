<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\OtpCode;
use App\Modules\Notifications\Jobs\SendSms;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Emissão e validação de códigos OTP.
 *
 * Regras (config/longa.php → otp):
 *  - código de N dígitos, guardado só com hash, válido durante `ttl` segundos;
 *  - intervalo mínimo entre envios por telefone e limites por hora por telefone e por IP;
 *  - `max_attempts` tentativas erradas por código; ao esgotar, o telefone fica bloqueado `lockout_minutes`.
 *
 * As mensagens de erro são neutras: nunca revelam se o telefone tem conta.
 */
class OtpService
{
    public const PURPOSE_LOGIN = 'login';

    public function issue(string $phone, ?string $ip): void
    {
        $this->ensureNotLocked($phone);

        $config = config('longa.otp');

        $this->hit("otp:cooldown:{$phone}", 1, (int) $config['resend_cooldown'], 'otp_resend_too_soon', 'Aguarde um pouco antes de pedir um novo código.');
        $this->hit("otp:phone:{$phone}", (int) $config['max_sends_per_phone_per_hour'], 3600, 'otp_too_many_requests', 'Pediu demasiados códigos. Tente novamente mais tarde.');
        if ($ip !== null) {
            $this->hit("otp:ip:{$ip}", (int) $config['max_sends_per_ip_per_hour'], 3600, 'otp_too_many_requests', 'Pediu demasiados códigos. Tente novamente mais tarde.');
        }

        $code = $this->generateCode((int) $config['length']);

        DB::transaction(function () use ($phone, $ip, $code, $config) {
            // Só o código mais recente é válido
            OtpCode::query()->where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            OtpCode::query()->create([
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'purpose' => self::PURPOSE_LOGIN,
                'expires_at' => now()->addSeconds((int) $config['ttl']),
                'ip' => $ip,
            ]);
        });

        $minutes = intdiv((int) $config['ttl'], 60);
        SendSms::dispatch($phone, "LONGA: o seu código é {$code}. Válido durante {$minutes} minutos. Não o partilhe com ninguém.");
    }

    /**
     * Valida o código. Em caso de sucesso consome-o; em caso de erro conta a tentativa.
     *
     * @throws ApiException
     */
    public function verify(string $phone, string $code): void
    {
        $this->ensureNotLocked($phone);

        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw $this->invalidCode();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= (int) config('longa.otp.max_attempts')) {
                $otp->forceFill(['consumed_at' => now()])->save();
                $this->lock($phone);

                throw $this->lockedException();
            }

            throw $this->invalidCode();
        }

        // Consumo condicional: dois pedidos simultâneos com o mesmo código não entram os dois
        $consumed = OtpCode::query()->whereKey($otp->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            throw $this->invalidCode();
        }
    }

    public function isLocked(string $phone): bool
    {
        return Cache::has($this->lockKey($phone));
    }

    private function ensureNotLocked(string $phone): void
    {
        if ($this->isLocked($phone)) {
            throw $this->lockedException();
        }
    }

    private function lock(string $phone): void
    {
        $minutes = (int) config('longa.otp.lockout_minutes');
        Cache::put($this->lockKey($phone), now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes));
    }

    private function lockKey(string $phone): string
    {
        return "otp:lock:{$phone}";
    }

    private function hit(string $key, int $max, int $decaySeconds, string $code, string $message): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException($code, $message, 429, ['retry_after' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function invalidCode(): ApiException
    {
        return new ApiException('otp_invalid', 'Código inválido ou expirado.', 422);
    }

    private function lockedException(): ApiException
    {
        return new ApiException(
            'otp_locked',
            'Demasiadas tentativas. Por segurança, aguarde '.config('longa.otp.lockout_minutes').' minutos e tente novamente.',
            429,
            ['retry_after' => (int) config('longa.otp.lockout_minutes') * 60],
        );
    }
}
