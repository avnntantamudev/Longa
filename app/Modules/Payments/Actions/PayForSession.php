<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentPhase;
use App\Modules\Payments\Services\EscrowService;
use App\Modules\Payments\Services\PhasedPayments;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * O cliente paga o serviço na janela de pagamento (pending_payment, 10 min por defeito).
 *
 *  1. regista a cobrança (chave única por serviço: nunca há duas cobranças do mesmo serviço);
 *  2. chama o gateway FORA da transação de BD (chamada de rede), com a mesma chave de idempotência;
 *  3. sucesso: escrow (ledger) + serviço → scheduled; falha: serviço → payment_failed (técnico libertado).
 *
 * Repetir depois de pago devolve o serviço já agendado, sem nova cobrança.
 */
class PayForSession
{
    public function __construct(
        private readonly PaymentProvider $gateway,
        private readonly EscrowService $escrow,
        private readonly SessionLifecycle $lifecycle,
        private readonly AuditLogger $audit,
        private readonly PhasedPayments $phases,
    ) {}

    public function handle(ServiceRequest $session, User $client, string $method): ServiceRequest
    {
        if ($session->isCash()) {
            throw new ApiException('cash_session', 'Este serviço é pago em dinheiro ao técnico.', 409);
        }

        // Pagamento por fases: paga-se a fase que está a pagar
        if (PaymentPhase::query()->where('service_request_id', $session->id)->exists()) {
            return $this->phases->pay($session, $client, $method);
        }

        if (EscrowHold::query()->where('service_request_id', $session->id)->exists()) {
            return $session->refresh();
        }

        if (! $session->awaitingPayment()) {
            throw new ApiException('payment_not_expected', 'Este serviço já não está à espera de pagamento.', 409);
        }

        if ($session->payment_due_at?->isPast()) {
            throw new ApiException('payment_window_expired', 'O prazo para pagar terminou. Publique um novo pedido.', 410);
        }

        $key = "charge:session:{$session->id}";
        $charge = $this->registerCharge($session, $client, $method, $key);

        if ($charge->status !== ProviderResult::SUCCEEDED) {
            $result = $this->gateway->charge($session->amount, $client->phone, $method, $key, "BISCA · {$session->title}");
            $charge->forceFill([
                'status' => $result->status,
                'external_reference' => $result->reference,
                'failure_reason' => $result->failureReason,
                'gateway_payload' => $result->payload ?: null,
            ])->save();
        }

        $locked = DB::transaction(function () use ($session, $client, $charge) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);

            if ($charge->status === ProviderResult::FAILED) {
                if ($locked->awaitingPayment()) {
                    $this->lifecycle->transition($locked, RequestState::Cancelled, 'payment_failed', $client, ['reason' => $charge->failure_reason], [
                        'payment_status' => ServiceRequest::PAYMENT_FAILED, 'closed_reason' => 'payment_failed', 'cancelled_at' => now(),
                    ]);
                }

                return $locked;
            }

            if ($charge->status === ProviderResult::PENDING) {
                // Gateways assíncronos: a confirmação chega por webhook (fase do gateway real)
                return $locked;
            }

            $this->escrow->hold($locked, $charge);

            if ($locked->awaitingPayment()) {
                $locked->forceFill(['payment_status' => ServiceRequest::PAYMENT_HELD])->save();
                $this->lifecycle->record($locked, 'payment_confirmed', $client, [
                    'charge_id' => $charge->id, 'amount' => $charge->amount, 'method' => $charge->method,
                ]);
            }

            $this->audit->record('payments.charged', $charge, ['amount' => $charge->amount], $client->id);

            return $locked;
        });

        // Fora da transação: o cancelamento por pagamento recusado fica gravado antes de responder com o erro
        if ($charge->status === ProviderResult::FAILED) {
            throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($charge->failure_reason ?? 'tente outro meio.'), 402);
        }

        return $locked;
    }

    private function registerCharge(ServiceRequest $session, User $client, string $method, string $key): PaymentCharge
    {
        $existing = PaymentCharge::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            // Pedido ao gateway ainda em curso noutro processo; ao fim de 2 min (ex.: processo interrompido)
            // pode repetir-se com a mesma chave, que o gateway trata como o mesmo pagamento
            if ($existing->status === ProviderResult::PENDING && $existing->external_reference === null && $existing->created_at?->gt(now()->subMinutes(2))) {
                throw new ApiException('payment_in_progress', 'O pagamento já está a ser processado. Aguarde.', 409);
            }

            return $existing;
        }

        try {
            return PaymentCharge::query()->create([
                'service_request_id' => $session->id,
                'client_id' => $client->id,
                'gateway' => $this->gateway->name(),
                'method' => $method,
                'kind' => 'charge',
                'amount' => $session->amount,
                'status' => ProviderResult::PENDING,
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('payment_in_progress', 'O pagamento já está a ser processado. Aguarde.', 409);
        }
    }
}
