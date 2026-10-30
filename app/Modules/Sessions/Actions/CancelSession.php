<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Modules\Sessions\Events\SessionCancelled;
use App\Modules\Sessions\Services\RefundPolicy;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de um serviço agendado pelo cliente ou pelo técnico, com o reembolso da política
 * (RefundPolicy) lançado no ledger na mesma transação. Idempotente para quem já cancelou.
 */
class CancelSession
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly RefundPolicy $policy,
        private readonly ScoreRecorder $score,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ServiceRequest $session, User $actor, ?string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($session, $actor, $reason) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);
            $byClient = $locked->client_id === $actor->id;
            $target = $byClient ? 'cancelled_by_client' : 'cancelled_by_provider';

            if ($locked->state === RequestState::Cancelled && $locked->closed_reason === $target) {
                return $locked;
            }

            if (! in_array($locked->state, [RequestState::Accepted, RequestState::OnTheWay], true) || $locked->awaitingPayment()) {
                throw new ApiException('session_not_cancellable', 'Este serviço já não pode ser cancelado ('.$locked->state->label().').', 409);
            }

            $late = $this->policy->isLate($locked);
            $providerGross = $byClient ? $this->policy->clientCancellation($locked) : $this->policy->providerFault($locked);

            $this->lifecycle->transition($locked, RequestState::Cancelled, 'cancelled', $actor, [
                'reason' => $reason, 'late' => $late, 'provider_share' => $providerGross, 'refund' => $locked->amount - $providerGross,
            ], ['closed_reason' => $target, 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'cancelled_at' => now()]);

            $this->settlement->split($locked, $providerGross, $actor, $byClient ? 'client_cancellation' : 'provider_cancellation');

            if (! $byClient && $late) {
                $this->score->record($locked->provider_profile_id, ScoreEvent::LATE_CANCELLATION, $locked, ['hours_before' => (int) now()->diffInHours($locked->scheduled_at)]);
            }

            $this->audit->record('sessions.cancelled', $locked, ['reason' => $reason, 'closed_reason' => $target, 'provider_share' => $providerGross], $actor->id);
            SessionCancelled::dispatch($locked);

            return $locked;
        });
    }
}
