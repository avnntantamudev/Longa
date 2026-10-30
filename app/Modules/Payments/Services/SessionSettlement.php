<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Support\Api\ApiException;

/**
 * Efeito financeiro de um serviço concluído ou terminado sem serviço (um só sítio para todas as regras).
 * Chamar dentro da transação que faz a transição de estado do serviço.
 */
class SessionSettlement
{
    public function __construct(private readonly EscrowService $escrow, private readonly CommissionDebts $debts) {}

    /**
     * Serviço concluído: liberta o escrow para o técnico (pagamento eletrónico).
     */
    public function complete(ServiceRequest $session, ?User $actor, string $reason): void
    {
        if ($session->isCash()) {
            // Dinheiro: o fluxo da comissão em dívida acontece quando o cliente confirma o pagamento em mão
            return;
        }

        $this->requireHold($session);
        // O que ainda está retido (com pagamento por fases, as fases confirmadas já foram libertadas)
        $this->escrow->settle($session, $this->heldAmount($session), EscrowHold::RELEASED, $reason, $actor);
        // Comissões em dívida (serviços pagos em dinheiro) descontadas do novo saldo
        $this->debts->offset($session->provider_profile_id, "release:session:{$session->id}");
        WalletUpdated::dispatch($session->provider->user_id, 'session_completed', $session->id);
    }

    /**
     * Parte do valor para o técnico (antes da comissão), o resto é reembolsado ao cliente.
     */
    public function split(ServiceRequest $session, int $providerGross, ?User $actor, string $reason): void
    {
        if ($session->isCash()) {
            return;
        }

        $this->requireHold($session);
        $outcome = match (true) {
            $providerGross === $this->heldAmount($session) => EscrowHold::RELEASED,
            $providerGross === 0 => EscrowHold::REFUNDED,
            default => EscrowHold::PARTIALLY_REFUNDED,
        };

        $this->escrow->settle($session, $providerGross, $outcome, $reason, $actor);

        if ($providerGross > 0) {
            $this->debts->offset($session->provider_profile_id, "release:session:{$session->id}");
            WalletUpdated::dispatch($session->provider->user_id, $reason, $session->id);
        }
    }

    /**
     * Valor ainda retido no escrow para o serviço (0 se não houver retenção).
     */
    public function heldAmount(ServiceRequest $session): int
    {
        return (int) EscrowHold::query()->where('service_request_id', $session->id)->value('amount');
    }

    private function requireHold(ServiceRequest $session): void
    {
        if (! EscrowHold::query()->where('service_request_id', $session->id)->exists()) {
            throw new ApiException('no_escrow', 'Não há pagamento retido para este serviço.', 409);
        }
    }
}
