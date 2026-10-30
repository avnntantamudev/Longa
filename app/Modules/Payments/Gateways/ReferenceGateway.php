<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

/**
 * Referência de pagamento (simulada): o cliente paga num multicaixa ou no homebanking com entidade e referência.
 */
class ReferenceGateway extends SimulatedGateway
{
    public function name(): string
    {
        return 'reference_simulated';
    }

    protected function prefix(): string
    {
        return 'REF';
    }

    protected function payload(string $reference, int $amount): array
    {
        return ['entity' => '00000', 'reference' => substr((string) crc32($reference), 0, 9), 'amount' => $amount];
    }
}
