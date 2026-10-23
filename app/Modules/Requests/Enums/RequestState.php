<?php

declare(strict_types=1);

namespace App\Modules\Requests\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Pedido de aula: draft → searching → matched | expired | cancelled.
 */
enum RequestState: string implements StateEnum
{
    case Draft = 'draft';
    case Searching = 'searching';
    case Matched = 'matched';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Searching, self::Cancelled],
            self::Searching => [self::Matched, self::Expired, self::Cancelled],
            self::Matched, self::Expired, self::Cancelled => [],
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
     * Estados que contam para o limite de pedidos ativos do aluno.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Draft, self::Searching];
    }
}
