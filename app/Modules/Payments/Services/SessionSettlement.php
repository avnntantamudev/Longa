<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Notifications\PackageUpdateNotification;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Support\Api\ApiException;

/**
 * Efeito financeiro de um serviço concluído ou terminado sem serviço (um só sítio para todas as regras).
 * Chamar dentro da transação que faz a transição de estado do serviço.
 *
 * Aulas de um pacote não têm retenção própria: o dinheiro está retido no pacote. Cada aula liberta a parte do
 * técnico e/ou devolve a parte do cliente a partir dessa retenção; quando não sobra nenhuma aula por fechar, o
 * pacote fecha (pago se alguma aula foi paga ao técnico, cancelado se não).
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

        if ($session->isPackageLesson()) {
            $this->settleLesson($session, $session->amount, $actor, $reason);

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

        if ($session->isPackageLesson()) {
            $this->settleLesson($session, $providerGross, $actor, $reason);

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

    /**
     * Fecha uma aula de um pacote: $providerGross para o técnico, o resto da aula volta ao cliente.
     * Idempotente (as chaves do ledger são por aula). Fecha o pacote se esta era a última aula em aberto.
     */
    private function settleLesson(ServiceRequest $lesson, int $providerGross, ?User $actor, string $reason): void
    {
        /** @var ServiceRequest $package */
        $package = ServiceRequest::query()->lockForUpdate()->findOrFail($lesson->package_id);
        $this->requireHold($package);

        $providerGross = max(0, min($providerGross, $lesson->amount));
        $refund = $lesson->amount - $providerGross;

        if ($providerGross > 0) {
            $this->escrow->releasePart($package, $providerGross, "lesson:{$lesson->id}", $actor);
        }
        if ($refund > 0) {
            $this->escrow->refundPart($package, $refund, "lesson:{$lesson->id}", $actor);
        }

        $this->closePackageIfDone($package, $lesson, $actor, $reason);

        if ($providerGross > 0) {
            $this->debts->offset($lesson->provider_profile_id, "release:session:{$lesson->id}");
            WalletUpdated::dispatch($lesson->provider->user_id, $reason, $lesson->id);
        }
    }

    /**
     * Sem aulas por fechar: liquida o que ainda estiver retido (arredondamentos) e fecha o pacote.
     */
    private function closePackageIfDone(ServiceRequest $package, ServiceRequest $current, ?User $actor, string $reason): void
    {
        $open = $package->lessons()->whereKeyNot($current->id)
            ->whereNotIn('state', [RequestState::Paid->value, RequestState::Cancelled->value])->exists();
        if ($open) {
            return;
        }

        // Pago se alguma aula foi paga ao técnico (dada, falta do cliente, desistência tardia do cliente)
        $anyPaid = $this->releasedSomething($package);

        $held = $this->heldAmount($package);
        if ($held > 0) {
            $this->escrow->settle($package, $held, EscrowHold::RELEASED, $reason, $actor, ['last_lesson_id' => $current->id]);
        } else {
            $this->escrow->closeEmpty($package, $anyPaid ? EscrowHold::RELEASED : EscrowHold::REFUNDED);
        }

        if ($package->state === RequestState::Accepted) {
            if ($anyPaid) {
                $package->by($actor?->id, 'package_completed')->transitionTo(RequestState::Paid, ['confirmed_at' => now()]);
                $package->client->notify(new PackageUpdateNotification('completed', 'Pacote terminado',
                    "Terminou \"{$package->title}\". Diga-nos como correu: avalie o técnico.", "/servicos/{$package->id}"));
            } else {
                $package->by($actor?->id, 'package_cancelled')->transitionTo(RequestState::Cancelled, ['cancelled_at' => now(), 'closed_reason' => $package->closed_reason ?? 'package_cancelled']);
            }
        }
    }

    /** Alguma parte do pacote já foi paga ao técnico (libertações parciais por aula). */
    private function releasedSomething(ServiceRequest $package): bool
    {
        return LedgerTransaction::query()
            ->whereIn('idempotency_key', $package->lessons()->pluck('id')->map(fn ($id) => "escrow:release:lesson:{$id}")->all())
            ->exists();
    }

    private function requireHold(ServiceRequest $session): void
    {
        if (! EscrowHold::query()->where('service_request_id', $session->id)->exists()) {
            throw new ApiException('no_escrow', 'Não há pagamento retido para este serviço.', 409);
        }
    }
}
