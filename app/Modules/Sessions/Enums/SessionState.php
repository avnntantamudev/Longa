<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Aula. Nesta fase só existe "scheduled"; a Fase 3 acrescenta confirmação por QR, conclusão,
 * cancelamento e falta.
 */
enum SessionState: string implements StateEnum
{
    case Scheduled = 'scheduled';

    public function transitions(): array
    {
        return [];
    }

    public function canTransitionTo(StateEnum $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return false;
    }
}
