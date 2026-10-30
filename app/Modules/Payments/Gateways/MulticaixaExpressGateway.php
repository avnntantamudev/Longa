<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

/**
 * Multicaixa Express (simulado): o cliente aprova o pagamento na app do banco.
 */
class MulticaixaExpressGateway extends SimulatedGateway
{
    public function name(): string
    {
        return 'multicaixa_express_simulated';
    }

    protected function prefix(): string
    {
        return 'MCX';
    }
}
