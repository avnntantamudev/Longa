<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Notifications\Notifications\SecurityNotification;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PIN de 6 dígitos e aparelhos de confiança.
 *
 *  - O PIN fica cifrado (bcrypt/argon, como uma palavra-passe) e nunca é devolvido nem registado.
 *  - Entrar com PIN exige o token secreto do aparelho (obtido ao entrar com o código SMS/WhatsApp).
 *  - Tentativas erradas por aparelho: à 5.ª bloqueia 15 min; à 10.ª o aparelho deixa de ser de confiança
 *    (volta a ser preciso o código) e o dono é avisado. A contagem é feita com a linha bloqueada
 *    (tentativas simultâneas não escapam ao limite).
 *  - PIN confirmado recentemente (5 min) autoriza operações com dinheiro.
 */
class PinService
{
    public const LENGTH = 6;

    public const LOCK_AFTER = 5;

    public const LOCK_MINUTES = 15;

    public const REVOKE_AFTER = 10;

    public const RECENT_SECONDS = 300;

    /** PINs demasiado óbvios (além de dígitos repetidos e sequências) */
    private const COMMON = ['123123', '121212', '112233', '696969', '159753', '147258', '258369', '102030', '101010', '131313', '123321', '000123', '111222'];

    public function __construct(private readonly AuditLogger $audit, private readonly AuthSessionService $sessions) {}

    /**
     * Motivo por que o PIN é fraco (null se for aceitável).
     */
    public function weakness(string $pin, ?string $phone = null): ?string
    {
        if (! preg_match('/^\d{'.self::LENGTH.'}$/', $pin)) {
            return 'O PIN tem de ter '.self::LENGTH.' dígitos.';
        }
        if (count(array_unique(str_split($pin))) === 1) {
            return 'Não use o mesmo dígito repetido.';
        }
        if (str_contains('01234567890', $pin) || str_contains('09876543210', $pin)) {
            return 'Não use números seguidos (ex.: 123456).';
        }
        if (in_array($pin, self::COMMON, true) || preg_match('/^(\d)(\d)\1\2\1\2$|^(\d{3})\3$/', $pin)) {
            return 'Esse PIN é fácil de adivinhar. Escolha outro.';
        }
        if ($phone !== null && str_ends_with(preg_replace('/\D/', '', $phone) ?? '', $pin)) {
            return 'Não use os últimos dígitos do seu telefone.';
        }

        return null;
    }

    /**
     * Define ou troca o PIN. Ao trocar, os outros aparelhos deixam de ser de confiança.
     */
    public function set(User $user, string $pin, ?TrustedDevice $keep = null): void
    {
        if (($reason = $this->weakness($pin, $user->phone)) !== null) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['pin' => [$reason]]);
        }

        $changing = $user->pin_hash !== null;
        $user->forceFill(['pin_hash' => Hash::make($pin), 'pin_set_at' => now()])->save();

        if ($changing) {
            TrustedDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')
                ->when($keep, fn ($q) => $q->whereKeyNot($keep->id))
                ->update(['revoked_at' => now(), 'revoked_reason' => 'pin_changed']);
            $user->notify(new SecurityNotification(SecurityNotification::PIN_CHANGED));
        }

        $this->audit->record($changing ? 'auth.pin_changed' : 'auth.pin_created', $user, [], $user->id);
    }

    /**
     * Regista o aparelho como de confiança e devolve o token secreto (só existe nesta resposta).
     *
     * @return array{device: TrustedDevice, token: string}
     */
    public function trust(User $user, Request $request, ?string $name = null): array
    {
        $token = Str::random(64);
        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'name' => Str::limit($name ?: $this->sessions->deviceNameFrom($request), 120, ''),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
        $device->forceFill(['last_used_at' => now()])->save();

        $previous = TrustedDevice::query()->where('user_id', $user->id)->whereKeyNot($device->id)->exists();
        if ($previous) {
            $user->notify(new SecurityNotification(SecurityNotification::NEW_DEVICE, $device->name));
        }
        $this->audit->record('auth.device_trusted', $device, ['name' => $device->name], $user->id);

        return ['device' => $device, 'token' => $token];
    }

    public function findDevice(?string $token): ?TrustedDevice
    {
        if ($token === null || strlen($token) !== 64) {
            return null;
        }

        $device = TrustedDevice::query()->where('token_hash', hash('sha256', $token))->with('user')->first();

        return $device !== null && $device->isActive() && ! $device->user->isSuspended() ? $device : null;
    }

    /**
     * Verifica o PIN neste aparelho. Lança exceção com a mensagem certa se falhar.
     */
    public function attempt(TrustedDevice $device, string $pin): User
    {
        $result = DB::transaction(function () use ($device, $pin): array {
            /** @var TrustedDevice $locked */
            $locked = TrustedDevice::query()->lockForUpdate()->findOrFail($device->id);
            $user = $locked->user;

            if (! $locked->isActive()) {
                return ['error' => 'device_not_trusted'];
            }
            if ($locked->isLocked()) {
                return ['error' => 'pin_locked', 'until' => $locked->locked_until];
            }
            if ($user->pin_hash === null) {
                return ['error' => 'pin_not_set'];
            }

            if (Hash::check($pin, $user->pin_hash)) {
                $locked->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_at' => now()])->save();

                return ['user' => $user];
            }

            $attempts = $locked->failed_attempts + 1;
            $changes = ['failed_attempts' => $attempts];
            if ($attempts >= self::REVOKE_AFTER) {
                $changes += ['revoked_at' => now(), 'revoked_reason' => 'too_many_attempts'];
            } elseif ($attempts % self::LOCK_AFTER === 0) {
                $changes += ['locked_until' => now()->addMinutes(self::LOCK_MINUTES)];
            }
            $locked->forceFill($changes)->save();

            return ['error' => $attempts >= self::REVOKE_AFTER ? 'device_revoked' : 'pin_invalid', 'left' => self::REVOKE_AFTER - $attempts, 'user' => $user, 'locked' => isset($changes['locked_until'])];
        });

        if (isset($result['error'])) {
            $this->fail($device, $result);
        }

        return $result['user'];
    }

    /**
     * Marca o PIN como confirmado para a sessão atual (pagamentos nos próximos 5 minutos).
     */
    public function markRecent(string $authSessionId): void
    {
        Cache::put("pin-ok:{$authSessionId}", true, self::RECENT_SECONDS);
    }

    public function isRecent(string $authSessionId): bool
    {
        return Cache::get("pin-ok:{$authSessionId}") === true;
    }

    public function revoke(TrustedDevice $device, string $reason): void
    {
        $device->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fail(TrustedDevice $device, array $result): never
    {
        match ($result['error']) {
            'pin_locked' => throw new ApiException('pin_locked', 'Demasiadas tentativas. Tente de novo dentro de '.max(1, (int) ceil(now()->diffInMinutes($result['until']))).' minuto(s) ou entre com o código SMS.', 429),
            'pin_not_set' => throw new ApiException('pin_not_set', 'Ainda não criou o PIN. Entre com o código SMS.', 409),
            'device_not_trusted' => throw new ApiException('device_not_trusted', 'Este aparelho já não está autorizado. Entre com o código SMS.', 401),
            default => null,
        };

        $this->audit->record('auth.pin_failed', $device, ['attempts' => self::REVOKE_AFTER - $result['left']], $device->user_id);

        if ($result['error'] === 'device_revoked') {
            $result['user']->notify(new SecurityNotification(SecurityNotification::DEVICE_BLOCKED, $device->name));

            throw new ApiException('device_not_trusted', 'PIN errado demasiadas vezes. Por segurança, entre com o código SMS.', 401);
        }

        throw new ApiException('pin_invalid', $result['locked']
            ? 'PIN errado. Bloqueado durante '.self::LOCK_MINUTES.' minutos.'
            : 'PIN errado.', 422);
    }
}
