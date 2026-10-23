<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Support\ProviderResult;
use Illuminate\Support\Facades\Log;

/**
 * Gateway simulado: nenhum dinheiro real é movido.
 *
 * Resultado configurável em PAYMENT_MOCK_OUTCOME (succeeded | failed). Para testar falhas sem mudar a
 * configuração, telefones terminados em 0000 são sempre recusados ("saldo insuficiente").
 * A mesma chave de idempotência devolve sempre a mesma referência (como um gateway real deve fazer).
 */
class MockPaymentProvider implements PaymentProvider
{
    public function __construct(private readonly string $outcome = ProviderResult::SUCCEEDED) {}

    public function name(): string
    {
        return 'mock';
    }

    public function charge(int $amount, string $phone, string $method, string $idempotencyKey, string $description): ProviderResult
    {
        $reference = 'MOCK-CHG-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));

        if ($this->outcome === ProviderResult::FAILED || str_ends_with($phone, '0000')) {
            Log::info('payment.mock.charge_failed', ['amount' => $amount, 'method' => $method, 'reference' => $reference]);

            return new ProviderResult(ProviderResult::FAILED, $reference, 'Saldo insuficiente (simulado).');
        }

        Log::info('payment.mock.charge', ['amount' => $amount, 'method' => $method, 'reference' => $reference, 'description' => $description]);

        return new ProviderResult(ProviderResult::SUCCEEDED, $reference, payload: ['simulated' => true]);
    }

    public function refund(string $chargeReference, int $amount, string $idempotencyKey): ProviderResult
    {
        $reference = 'MOCK-RFD-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));
        Log::info('payment.mock.refund', ['charge' => $chargeReference, 'amount' => $amount, 'reference' => $reference]);

        return new ProviderResult(ProviderResult::SUCCEEDED, $reference, payload: ['simulated' => true]);
    }
}
