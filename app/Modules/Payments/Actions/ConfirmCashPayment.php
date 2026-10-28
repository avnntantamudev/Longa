<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Serviço pago em dinheiro: depois de concluído (QR), o cliente confirma que pagou em mão.
 * Regista a comissão como dívida do técnico e compensa com o saldo disponível, se houver.
 * Idempotente. A regra automática R5 faz o mesmo se o cliente não confirmar em 48 h.
 */
class ConfirmCashPayment
{
    public function __construct(
        private readonly CommissionDebts $debts,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function handle(ServiceJob $session, ?User $client, string $reason = 'client_confirmed'): ServiceJob
    {
        $result = DB::transaction(function () use ($session, $client, $reason) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);

            if (! $locked->isCash()) {
                throw new ApiException('not_cash_session', 'Este serviço não é paga em dinheiro.', 409);
            }

            if ($locked->cash_confirmed_at !== null) {
                return ['session' => $locked, 'new' => false];
            }

            if ($locked->state !== SessionState::Completed) {
                throw new ApiException('session_not_completed', 'Confirme primeiro o serviço com o QR do técnico.', 409);
            }

            $this->debts->register($locked, $client, $reason);
            $locked->forceFill(['cash_confirmed_at' => now()])->save();
            $this->lifecycle->record($locked, 'cash_confirmed', $client, ['reason' => $reason, 'commission_due' => $locked->commission]);

            return ['session' => $locked, 'new' => true];
        });

        if ($result['new']) {
            $this->debts->offset($session->provider_profile_id, "session:{$session->id}");
            WalletUpdated::dispatch($session->provider->user_id, 'cash_commission_due', $session->id);
        }

        return $result['session'];
    }
}
