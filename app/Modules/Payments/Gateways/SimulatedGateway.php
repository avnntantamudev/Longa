<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Support\ProviderResult;
use Illuminate\Support\Facades\Log;

/**
 * Base dos meios digitais simulados (stubs): respondem como o fornecedor real responderia, sem mover dinheiro.
 * Para ligar um fornecedor real, cria-se uma classe com a mesma interface e troca-se no PaymentsServiceProvider.
 * Telefones terminados em 0000 simulam uma recusa.
 */
abstract class SimulatedGateway implements PaymentProvider
{
    public function __construct(private readonly string $outcome = ProviderResult::SUCCEEDED) {}

    /** Prefixo das referências (ex.: MCX) */
    abstract protected function prefix(): string;

    /**
     * Dados extra devolvidos ao cliente (ex.: entidade e referência para pagar no multicaixa).
     *
     * @return array<string, mixed>
     */
    protected function payload(string $reference, int $amount): array
    {
        return [];
    }

    public function charge(int $amount, string $phone, string $method, string $idempotencyKey, string $description): ProviderResult
    {
        $reference = $this->prefix().'-CHG-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));

        if ($this->outcome === ProviderResult::FAILED || str_ends_with($phone, '0000')) {
            Log::info('payment.simulated.charge_failed', ['gateway' => $this->name(), 'amount' => $amount, 'reference' => $reference]);

            return new ProviderResult(ProviderResult::FAILED, $reference, 'Saldo insuficiente (simulado).');
        }

        Log::info('payment.simulated.charge', ['gateway' => $this->name(), 'amount' => $amount, 'reference' => $reference, 'description' => $description]);

        return new ProviderResult(ProviderResult::SUCCEEDED, $reference, payload: ['simulated' => true, ...$this->payload($reference, $amount)]);
    }

    public function refund(string $chargeReference, int $amount, string $idempotencyKey): ProviderResult
    {
        $reference = $this->prefix().'-RFD-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));
        Log::info('payment.simulated.refund', ['gateway' => $this->name(), 'charge' => $chargeReference, 'amount' => $amount, 'reference' => $reference]);

        return new ProviderResult(ProviderResult::SUCCEEDED, $reference, payload: ['simulated' => true]);
    }
}
