<?php

declare(strict_types=1);

namespace App\Modules\Qr\Services;

use App\Modules\Qr\Models\SessionQrToken;
use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lotes de tokens de confirmação (docs/threat-model.md).
 *
 *  - token: 128 bits de random_bytes, base64url (22 caracteres), guardado como HMAC-SHA256 com a APP_KEY;
 *  - código de 6 dígitos por janela, guardado como HMAC(serviço:código);
 *  - lote: N janelas consecutivas de 30 s (por defeito 10 = 5 min), para o ecrã do técnico continuar a
 *    mostrar QR válidos mesmo com a rede a falhar; cada token só é aceite na sua janela (+ margem) e uma vez.
 */
class QrTokenService
{
    /** Margem para a latência entre o ecrã do técnico e o servidor */
    private const GRACE_SECONDS = 15;

    /**
     * @return array{batch_id: string, rotation_seconds: int, tokens: list<array{token: string, code: string, valid_from: string, expires_at: string}>}
     */
    public function issueBatch(ServiceRequest $session): array
    {
        $rotation = (int) config('longa.session_rules.qr_rotation_seconds');
        $size = (int) config('longa.session_rules.qr_batch_size');
        $batchId = (string) Str::uuid();
        $start = now()->startOfSecond();

        $tokens = [];
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $validFrom = $start->copy()->addSeconds($i * $rotation);
            $expiresAt = $validFrom->copy()->addSeconds($rotation + self::GRACE_SECONDS);

            $rows[] = [
                'service_request_id' => $session->id,
                'provider_profile_id' => $session->provider_profile_id,
                'batch_id' => $batchId,
                'token_hash' => $this->hashToken($token),
                'code_hash' => $this->hashCode($session->id, $code),
                'valid_from' => $validFrom,
                'expires_at' => $expiresAt,
                'status' => SessionQrToken::ACTIVE,
                'created_at' => now(),
            ];
            $tokens[] = ['token' => $token, 'code' => $code, 'valid_from' => $validFrom->toIso8601String(), 'expires_at' => $expiresAt->toIso8601String()];
        }

        SessionQrToken::query()->insert($rows);

        return ['batch_id' => $batchId, 'rotation_seconds' => $rotation, 'tokens' => $tokens];
    }

    /**
     * Encontra o token válido agora para este serviço (pelo QR ou pelo código), com bloqueio da linha.
     * Chamar dentro de uma transação.
     */
    public function findValid(ServiceRequest $session, ?string $token, ?string $code, ?Carbon $at = null): ?SessionQrToken
    {
        $at ??= now();

        $query = SessionQrToken::query()
            ->where('service_request_id', $session->id)
            ->where('status', SessionQrToken::ACTIVE)
            ->where('valid_from', '<=', $at)
            ->where('expires_at', '>', $at)
            ->lockForUpdate();

        if ($token !== null && $token !== '') {
            return $query->where('token_hash', $this->hashToken($token))->first();
        }

        if ($code !== null && preg_match('/^\d{6}$/', $code) === 1) {
            return $query->where('code_hash', $this->hashCode($session->id, $code))->first();
        }

        return null;
    }

    public function markUsed(SessionQrToken $token, int $userId): bool
    {
        $updated = SessionQrToken::query()
            ->whereKey($token->id)
            ->where('status', SessionQrToken::ACTIVE)
            ->update(['status' => SessionQrToken::USED, 'used_at' => now(), 'used_by' => $userId]);

        // Os restantes tokens do serviço deixam de valer (capturas de ecrã antigas ou futuras)
        SessionQrToken::query()
            ->where('service_request_id', $token->service_request_id)
            ->where('status', SessionQrToken::ACTIVE)
            ->update(['status' => SessionQrToken::REVOKED]);

        return $updated === 1;
    }

    /**
     * Regista a tentativa falhada na janela atual (para auditoria; o bloqueio é feito pelo limitador).
     */
    public function recordFailedAttempt(ServiceRequest $session): void
    {
        DB::table('session_qr_tokens')
            ->where('service_request_id', $session->id)
            ->where('status', SessionQrToken::ACTIVE)
            ->where('valid_from', '<=', now())
            ->where('expires_at', '>', now())
            ->increment('attempts');
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'qr:'.$token, (string) config('app.key'));
    }

    private function hashCode(int $sessionId, string $code): string
    {
        return hash_hmac('sha256', "code:{$sessionId}:{$code}", (string) config('app.key'));
    }
}
