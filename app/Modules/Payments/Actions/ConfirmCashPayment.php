<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Aula paga em dinheiro: depois de concluída (QR), o aluno confirma que pagou em mão.
 * Regista a comissão como dívida do formador e compensa com o saldo disponível, se houver.
 * Idempotente. A regra automática R5 faz o mesmo se o aluno não confirmar em 48 h.
 */
class ConfirmCashPayment
{
    public function __construct(
        private readonly CommissionDebts $debts,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function handle(TrainingSession $session, ?User $student, string $reason = 'student_confirmed'): TrainingSession
    {
        $result = DB::transaction(function () use ($session, $student, $reason) {
            /** @var TrainingSession $locked */
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $locked->isCash()) {
                throw new ApiException('not_cash_session', 'Esta aula não é paga em dinheiro.', 409);
            }

            if ($locked->cash_confirmed_at !== null) {
                return ['session' => $locked, 'new' => false];
            }

            if ($locked->state !== SessionState::Completed) {
                throw new ApiException('session_not_completed', 'Confirme primeiro a aula com o QR do formador.', 409);
            }

            $this->debts->register($locked, $student, $reason);
            $locked->forceFill(['cash_confirmed_at' => now()])->save();
            $this->lifecycle->record($locked, 'cash_confirmed', $student, ['reason' => $reason, 'commission_due' => $locked->commission]);

            return ['session' => $locked, 'new' => true];
        });

        if ($result['new']) {
            $this->debts->offset($session->trainer_profile_id, "session:{$session->id}");
            WalletUpdated::dispatch($session->trainer->user_id, 'cash_commission_due', $session->id);
        }

        return $result['session'];
    }
}
