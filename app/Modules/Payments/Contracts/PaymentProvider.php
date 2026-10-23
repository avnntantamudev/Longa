<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Support\ProviderResult;

/**
 * Gateway de pagamento. Nesta fase só existe o mock (MockPaymentProvider).
 * O gateway real (ex.: EMIS GPO para Multicaixa Express, ProxyPay para referências) implementa esta
 * interface depois de o cliente o escolher.
 *
 * As chamadas recebem uma chave de idempotência: o gateway real deve usá-la para nunca cobrar duas vezes.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Cobra o aluno. Pode devolver "pending" (ex.: aprovação na app Multicaixa Express) e confirmar depois.
     *
     * @param  string  $phone  telefone do aluno (E.164), usado pelo Multicaixa Express
     */
    public function charge(int $amount, string $phone, string $method, string $idempotencyKey, string $description): ProviderResult;

    /**
     * Devolve dinheiro ao aluno (total ou parcial) de uma cobrança anterior.
     */
    public function refund(string $chargeReference, int $amount, string $idempotencyKey): ProviderResult;
}
