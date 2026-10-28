<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Aula (docs/state-machines.md).
 *
 *  pending_payment → scheduled | payment_failed
 *  scheduled → in_progress | cancelled_by_client | cancelled_by_provider | no_show_provider | no_show_client
 *            | awaiting_confirmation (regra R1: nunca iniciada)
 *  in_progress → awaiting_confirmation
 *  awaiting_confirmation → completed | disputed | refunded (regra R1 sem resposta do aluno)
 *  no_show_provider | no_show_client → disputed (a outra parte contesta dentro do prazo)
 *  disputed → completed | refunded | partially_refunded (decisão do admin)
 */
enum SessionState: string implements StateEnum
{
    case PendingPayment = 'pending_payment';
    case PaymentFailed = 'payment_failed';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case CancelledByClient = 'cancelled_by_client';
    case CancelledByProvider = 'cancelled_by_provider';
    case NoShowProvider = 'no_show_provider';
    case NoShowClient = 'no_show_client';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function transitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Scheduled, self::PaymentFailed],
            self::Scheduled => [
                self::InProgress, self::CancelledByClient, self::CancelledByProvider,
                self::NoShowProvider, self::NoShowClient, self::AwaitingConfirmation,
            ],
            self::InProgress => [self::AwaitingConfirmation],
            self::AwaitingConfirmation => [self::Completed, self::Disputed, self::Refunded],
            self::NoShowProvider, self::NoShowClient => [self::Disputed],
            self::Disputed => [self::Completed, self::Refunded, self::PartiallyRefunded],
            self::PaymentFailed, self::Completed, self::CancelledByClient, self::CancelledByProvider,
            self::Refunded, self::PartiallyRefunded => [],
        };
    }

    public function canTransitionTo(StateEnum $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /**
     * Aulas que ocupam o horário do formador (conflitos no matching).
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::PendingPayment, self::Scheduled, self::InProgress];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'A aguardar pagamento',
            self::PaymentFailed => 'Pagamento falhou',
            self::Scheduled => 'Agendada',
            self::InProgress => 'Em curso',
            self::AwaitingConfirmation => 'A aguardar confirmação',
            self::Completed => 'Concluída',
            self::CancelledByClient => 'Cancelada pelo aluno',
            self::CancelledByProvider => 'Cancelada pelo formador',
            self::NoShowProvider => 'Falta do formador',
            self::NoShowClient => 'Falta do aluno',
            self::Disputed => 'Em disputa',
            self::Refunded => 'Reembolsada',
            self::PartiallyRefunded => 'Reembolsada parcialmente',
        };
    }
}
