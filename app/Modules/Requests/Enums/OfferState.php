<?php

declare(strict_types=1);

namespace App\Modules\Requests\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Oferta TRIM TRIM: sent → viewed → accepted | declined | expired | lost.
 * "lost": outro técnico aceitou primeiro (ou o pedido foi cancelado/expirou).
 */
enum OfferState: string implements StateEnum
{
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Lost = 'lost';

    public function transitions(): array
    {
        return match ($this) {
            self::Sent => [self::Viewed, self::Accepted, self::Declined, self::Expired, self::Lost],
            self::Viewed => [self::Accepted, self::Declined, self::Expired, self::Lost],
            // Nova tentativa de um pedido "sem técnico disponível": volta a oferecer a quem não respondeu
            self::Expired, self::Lost => [self::Sent],
            self::Accepted, self::Declined => [],
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
     * Ofertas a que o técnico ainda pode responder.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Sent, self::Viewed];
    }
}
