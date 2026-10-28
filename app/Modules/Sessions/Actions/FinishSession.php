<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Qr\Services\QrTokenService;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * "Terminar serviço e mostrar QR": in_progress → awaiting_confirmation e emite um lote de QR.
 * Também serve para pedir um novo lote enquanto o serviço espera confirmação (o ecrã renova sozinho).
 */
class FinishSession
{
    public function __construct(private readonly SessionLifecycle $lifecycle, private readonly QrTokenService $qr) {}

    /**
     * @return array{session: ServiceJob, qr: array{batch_id: string, rotation_seconds: int, tokens: list<array{token: string, code: string, valid_from: string, expires_at: string}>}}
     */
    public function handle(ServiceJob $session, User $provider): array
    {
        return DB::transaction(function () use ($session, $provider) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === SessionState::InProgress) {
                $this->lifecycle->transition($locked, SessionState::AwaitingConfirmation, 'finished', $provider, [], [
                    'ended_at' => now(),
                    'awaiting_since' => now(),
                ]);
            } elseif ($locked->state !== SessionState::AwaitingConfirmation) {
                throw new ApiException('session_not_finishable', 'Inicie o serviço antes de mostrar o QR.', 409);
            }

            $windowEnd = $locked->ends_at?->copy()->addHours((int) config('longa.session_rules.qr_window_hours_after_end'));
            if ($windowEnd !== null && now()->gt($windowEnd)) {
                throw new ApiException('qr_window_closed', 'O prazo para confirmar o serviço por QR terminou. O cliente pode confirmar na app.', 409);
            }

            $batch = $this->qr->issueBatch($locked);
            $this->lifecycle->record($locked, 'qr_issued', $provider, ['batch_id' => $batch['batch_id'], 'tokens' => count($batch['tokens'])]);
            QrIssued::dispatch($locked);

            return ['session' => $locked, 'qr' => $batch];
        });
    }
}
