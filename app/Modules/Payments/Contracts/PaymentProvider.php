<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Support\ProviderResult;

/**
 * Gateway de pagamento de um meio digital. Implementações: Gateways\* (simuladas) e o PaymentGatewayRouter, que escolhe o fornecedor pelo meio.
 * O gateway real (ex.: EMIS GPO para Multicaixa Express, ProxyPay para referências) implementa esta
 * interface depois de o cliente o escolher.
 *
 * As chamadas recebem uma chave de idempotência: o gateway real deve usá-la para nunca cobrar duas vezes.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Cobra o cliente. Pode devolver "pending" (ex.: aprovação na app Multicaixa Express) e confirmar depois.
     *
     * @param  string  $phone  telefone do cliente (E.164), usado pelo Multicaixa Express
     */
    public function charge(int $amount, string $phone, string $method, string $idempotencyKey, string $description): ProviderResult;

    /**
     * Devolve dinheiro ao cliente (total ou parcial) de uma cobrança anterior.
     */
    public function refund(string $chargeReference, int $amount, string $idempotencyKey): ProviderResult;
}
