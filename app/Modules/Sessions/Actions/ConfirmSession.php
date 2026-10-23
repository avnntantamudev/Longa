<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Qr\Services\QrTokenService;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * O aluno lê o QR do formador (ou escreve o código de 6 dígitos) e confirma a aula.
 *
 * Numa só transação, com a aula bloqueada: valida o token (desta aula, na janela, não usado), marca-o
 * como usado, conclui a aula e liberta o escrow. Confirmar de novo devolve a aula concluída sem libertar
 * o dinheiro outra vez. Ver docs/threat-model.md.
 */
class ConfirmSession
{
    public function __construct(
        private readonly QrTokenService $qr,
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(TrainingSession $session, User $student, ?string $token, ?string $code, ?float $latitude = null, ?float $longitude = null): TrainingSession
    {
        $lockKey = "qr-failures:session:{$session->id}";
        $maxFailures = (int) config('longa.session_rules.qr_max_failed_attempts');

        if (RateLimiter::tooManyAttempts($lockKey, $maxFailures)) {
            throw new ApiException('qr_locked', 'Demasiadas tentativas erradas. Aguarde alguns minutos e tente de novo.', 429, [
                'retry_after' => RateLimiter::availableIn($lockKey),
            ]);
        }

        $result = DB::transaction(function () use ($session, $student, $token, $code, $latitude, $longitude) {
            /** @var TrainingSession $locked */
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);

            // Idempotência: já confirmada (por esta ou por uma leitura simultânea)
            if ($locked->state === SessionState::Completed) {
                return ['session' => $locked, 'error' => null];
            }

            if (! in_array($locked->state, [SessionState::InProgress, SessionState::AwaitingConfirmation], true)) {
                return ['session' => $locked, 'error' => new ApiException('session_not_confirmable', 'Esta aula não pode ser confirmada no estado atual ('.$locked->state->label().').', 409)];
            }

            $windowStart = $locked->scheduled_at;
            $windowEnd = $locked->ends_at?->copy()->addHours((int) config('longa.session_rules.qr_window_hours_after_end'));
            if (now()->lt($windowStart) || ($windowEnd !== null && now()->gt($windowEnd))) {
                return ['session' => $locked, 'error' => new ApiException('qr_outside_window', 'Fora do período em que a aula pode ser confirmada por QR.', 409)];
            }

            $qrToken = $this->qr->findValid($locked, $token, $code);
            if ($qrToken === null || ! $this->qr->markUsed($qrToken, $student->id)) {
                $this->qr->recordFailedAttempt($locked);

                return ['session' => $locked, 'error' => 'invalid'];
            }

            $metadata = ['method' => $token ? 'qr' : 'code', 'token_id' => $qrToken->id];
            $distance = $this->distance($locked, $latitude, $longitude);
            if ($distance !== null) {
                $metadata['distance_m'] = $distance;
            }

            $this->lifecycle->record($locked, 'qr_scanned', $student, $metadata);

            if ($distance !== null && $distance > (int) config('longa.session_rules.presence_risk_meters') && $locked->modality === 'in_person') {
                // Sinal de risco para o backoffice (nunca bloqueia a confirmação)
                $this->lifecycle->record($locked, 'risk_flagged', $student, ['reason' => 'far_from_location', 'distance_m' => $distance]);
            }

            $this->lifecycle->transition($locked, SessionState::Completed, 'confirmed', $student, $metadata, ['confirmed_at' => now()]);
            $this->settlement->complete($locked, $student, 'qr_confirmed');

            SessionConfirmed::dispatch($locked);
            $this->audit->record('sessions.confirmed', $locked, $metadata, $student->id);

            return ['session' => $locked, 'error' => null];
        });

        if ($result['error'] === 'invalid') {
            RateLimiter::hit($lockKey, (int) config('longa.session_rules.qr_attempt_window_minutes') * 60);

            throw new ApiException('qr_invalid', 'QR ou código inválido ou expirado. Peça ao formador para mostrar o QR atual.', 422);
        }

        if ($result['error'] instanceof ApiException) {
            throw $result['error'];
        }

        RateLimiter::clear($lockKey);

        return $result['session'];
    }

    private function distance(TrainingSession $session, ?float $latitude, ?float $longitude): ?int
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        // Haversine (metros)
        $r = 6_371_000;
        $dLat = deg2rad($latitude - $session->latitude);
        $dLng = deg2rad($longitude - $session->longitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($session->latitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }
}
