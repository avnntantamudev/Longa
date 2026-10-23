<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use App\Support\Api\ApiException;

/**
 * Transição não permitida pela máquina de estados, ou o estado mudou entretanto (corrida).
 */
class InvalidTransition extends ApiException
{
    public static function notAllowed(string $entity, StateEnum $from, StateEnum $to): self
    {
        return new self('invalid_state_transition', 'Esta ação já não é possível neste momento.', 409, [
            'entity' => $entity,
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    public static function concurrent(string $entity, StateEnum $expected, StateEnum $to): self
    {
        return new self('state_changed', 'O estado foi alterado entretanto. Atualize e tente novamente.', 409, [
            'entity' => $entity,
            'expected' => $expected->value,
            'to' => $to->value,
        ]);
    }
}
