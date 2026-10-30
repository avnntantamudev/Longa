<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

/**
 * Cartão (simulado).
 */
class CardGateway extends SimulatedGateway
{
    public function name(): string
    {
        return 'card_simulated';
    }

    protected function prefix(): string
    {
        return 'CARD';
    }
}
