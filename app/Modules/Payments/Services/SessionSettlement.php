<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Support\Api\ApiException;

/**
 * Efeito financeiro de uma aula concluída ou terminada sem aula (um só sítio para todas as regras).
 * Chamar dentro da transação que faz a transição de estado da aula.
 */
class SessionSettlement
{
    public function __construct(private readonly EscrowService $escrow, private readonly CommissionDebts $debts) {}

    /**
     * Aula concluída: liberta o escrow para o formador (pagamento eletrónico).
     */
    public function complete(ServiceJob $session, ?User $actor, string $reason): void
    {
        if ($session->isCash()) {
            // Dinheiro: o fluxo da comissão em dívida acontece quando o aluno confirma o pagamento em mão
            return;
        }

        $this->requireHold($session);
        $this->escrow->settle($session, $session->amount, EscrowHold::RELEASED, $reason, $actor);
        // Comissões em dívida (aulas pagas em dinheiro) descontadas do novo saldo
        $this->debts->offset($session->provider_profile_id, "release:session:{$session->id}");
        WalletUpdated::dispatch($session->provider->user_id, 'session_completed', $session->id);
    }

    /**
     * Parte do valor para o formador (antes da comissão), o resto é reembolsado ao aluno.
     */
    public function split(ServiceJob $session, int $providerGross, ?User $actor, string $reason): void
    {
        if ($session->isCash()) {
            return;
        }

        $this->requireHold($session);
        $outcome = match (true) {
            $providerGross === $session->amount => EscrowHold::RELEASED,
            $providerGross === 0 => EscrowHold::REFUNDED,
            default => EscrowHold::PARTIALLY_REFUNDED,
        };

        $this->escrow->settle($session, $providerGross, $outcome, $reason, $actor);

        if ($providerGross > 0) {
            $this->debts->offset($session->provider_profile_id, "release:session:{$session->id}");
            WalletUpdated::dispatch($session->provider->user_id, $reason, $session->id);
        }
    }

    private function requireHold(ServiceJob $session): void
    {
        if (! EscrowHold::query()->where('service_job_id', $session->id)->exists()) {
            throw new ApiException('no_escrow', 'Não há pagamento retido para esta aula.', 409);
        }
    }
}
