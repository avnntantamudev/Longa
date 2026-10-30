<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Support\ProviderResult;

/**
 * Interface única de pagamentos: esconde o fornecedor de cada meio (Multicaixa Express, referência, cartão).
 * O resto do código cobra e reembolsa sempre por aqui; o dinheiro não passa pelo gateway.
 */
class PaymentGatewayRouter implements PaymentProvider
{
    /**
     * @param  array<string, PaymentProvider>  $drivers  meio de pagamento => fornecedor
     */
    public function __construct(private readonly array $drivers, private readonly string $name = 'mock') {}

    public function name(): string
    {
        return $this->name;
    }

    public function driverFor(string $method): ?PaymentProvider
    {
        return $this->drivers[$method] ?? null;
    }

    public function charge(int $amount, string $phone, string $method, string $idempotencyKey, string $description): ProviderResult
    {
        $driver = $this->driverFor($method);
        if ($driver === null) {
            return new ProviderResult(ProviderResult::FAILED, null, $method === PaymentMethod::Cash->value
                ? 'Pagamento em dinheiro: é feito em mão ao técnico.'
                : 'Meio de pagamento não suportado.');
        }

        $result = $driver->charge($amount, $phone, $method, $idempotencyKey, $description);

        return new ProviderResult($result->status, $result->reference, $result->failureReason, [...$result->payload, 'driver' => $driver->name()]);
    }

    /**
     * Reembolso pelo fornecedor da cobrança original (o prefixo da referência identifica-o).
     */
    public function refund(string $chargeReference, int $amount, string $idempotencyKey): ProviderResult
    {
        $driver = match (true) {
            str_starts_with($chargeReference, 'REF-') => $this->drivers[PaymentMethod::Reference->value] ?? null,
            str_starts_with($chargeReference, 'CARD-') => $this->drivers[PaymentMethod::Card->value] ?? null,
            default => $this->drivers[PaymentMethod::MulticaixaExpress->value] ?? null,
        };

        return $driver?->refund($chargeReference, $amount, $idempotencyKey)
            ?? new ProviderResult(ProviderResult::FAILED, null, 'Sem fornecedor para reembolsar.');
    }
}
