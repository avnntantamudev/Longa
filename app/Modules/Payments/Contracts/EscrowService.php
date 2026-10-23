<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Sessions\Models\TrainingSession;

/**
 * Custódia do pagamento de uma aula (escrow).
 *
 * Fase 2: só existe a implementação mock, que não move dinheiro. A Fase 3 liga esta interface
 * ao meio de pagamento real (Multicaixa Express / referência), depois de o cliente o confirmar.
 *
 *  - hold: reserva o valor pago pelo aluno quando a aula é criada;
 *  - release: liberta para o formador (valor − comissão) depois da confirmação por QR;
 *  - refund: devolve ao aluno (cancelamento, falta do formador).
 */
interface EscrowService
{
    /**
     * @return string referência da custódia, guardada em training_sessions.escrow_reference
     */
    public function hold(TrainingSession $session): string;

    public function release(TrainingSession $session): void;

    public function refund(TrainingSession $session, string $reason): void;
}
