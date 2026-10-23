<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\SessionCancelled;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\RefundPolicy;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de uma aula agendada pelo aluno ou pelo formador, com o reembolso da política
 * (RefundPolicy) lançado no ledger na mesma transação. Idempotente para quem já cancelou.
 */
class CancelSession
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly RefundPolicy $policy,
        private readonly ScoreRecorder $score,
    ) {}

    public function handle(TrainingSession $session, User $actor, ?string $reason): TrainingSession
    {
        return DB::transaction(function () use ($session, $actor, $reason) {
            /** @var TrainingSession $locked */
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            $byStudent = $locked->student_id === $actor->id;
            $target = $byStudent ? SessionState::CancelledByStudent : SessionState::CancelledByTrainer;

            if ($locked->state === $target) {
                return $locked;
            }

            if ($locked->state !== SessionState::Scheduled) {
                throw new ApiException('session_not_cancellable', 'Esta aula já não pode ser cancelada ('.$locked->state->label().').', 409);
            }

            $late = $this->policy->isLate($locked);
            $trainerGross = $byStudent ? $this->policy->studentCancellation($locked) : $this->policy->trainerFault($locked);

            $this->lifecycle->transition($locked, $target, 'cancelled', $actor, [
                'reason' => $reason, 'late' => $late, 'trainer_share' => $trainerGross, 'refund' => $locked->amount - $trainerGross,
            ]);

            $this->settlement->split($locked, $trainerGross, $actor, $byStudent ? 'student_cancellation' : 'trainer_cancellation');

            if (! $byStudent && $late) {
                $this->score->record($locked->trainer_profile_id, ScoreEvent::LATE_CANCELLATION, $locked, ['hours_before' => (int) now()->diffInHours($locked->scheduled_at)]);
            }

            SessionCancelled::dispatch($locked);

            return $locked;
        });
    }
}
