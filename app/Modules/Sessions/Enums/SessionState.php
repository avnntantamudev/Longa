<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Aula (docs/state-machines.md).
 *
 *  pending_payment → scheduled | payment_failed
 *  scheduled → in_progress | cancelled_by_student | cancelled_by_trainer | no_show_trainer | no_show_student
 *            | awaiting_confirmation (regra R1: nunca iniciada)
 *  in_progress → awaiting_confirmation
 *  awaiting_confirmation → completed | disputed | refunded (regra R1 sem resposta do aluno)
 *  no_show_trainer | no_show_student → disputed (a outra parte contesta dentro do prazo)
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
    case CancelledByStudent = 'cancelled_by_student';
    case CancelledByTrainer = 'cancelled_by_trainer';
    case NoShowTrainer = 'no_show_trainer';
    case NoShowStudent = 'no_show_student';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function transitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Scheduled, self::PaymentFailed],
            self::Scheduled => [
                self::InProgress, self::CancelledByStudent, self::CancelledByTrainer,
                self::NoShowTrainer, self::NoShowStudent, self::AwaitingConfirmation,
            ],
            self::InProgress => [self::AwaitingConfirmation],
            self::AwaitingConfirmation => [self::Completed, self::Disputed, self::Refunded],
            self::NoShowTrainer, self::NoShowStudent => [self::Disputed],
            self::Disputed => [self::Completed, self::Refunded, self::PartiallyRefunded],
            self::PaymentFailed, self::Completed, self::CancelledByStudent, self::CancelledByTrainer,
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
            self::CancelledByStudent => 'Cancelada pelo aluno',
            self::CancelledByTrainer => 'Cancelada pelo formador',
            self::NoShowTrainer => 'Falta do formador',
            self::NoShowStudent => 'Falta do aluno',
            self::Disputed => 'Em disputa',
            self::Refunded => 'Reembolsada',
            self::PartiallyRefunded => 'Reembolsada parcialmente',
        };
    }
}
