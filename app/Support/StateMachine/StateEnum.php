<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use BackedEnum;

/**
 * Enum de estados com transições explícitas.
 */
interface StateEnum extends BackedEnum
{
    /**
     * Estados para onde se pode ir a partir deste.
     *
     * @return list<static>
     */
    public function transitions(): array;

    public function canTransitionTo(self $to): bool;

    public function isFinal(): bool;
}
