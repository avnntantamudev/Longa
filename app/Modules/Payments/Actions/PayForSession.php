<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Services\EscrowService;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * O aluno paga a aula na janela de pagamento (pending_payment, 10 min por defeito).
 *
 *  1. regista a cobrança (chave única por aula: nunca há duas cobranças da mesma aula);
 *  2. chama o gateway FORA da transação de BD (chamada de rede), com a mesma chave de idempotência;
 *  3. sucesso: escrow (ledger) + aula → scheduled; falha: aula → payment_failed (formador libertado).
 *
 * Repetir depois de pago devolve a aula já agendada, sem nova cobrança.
 */
class PayForSession
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly EscrowService $escrow,
        private readonly SessionLifecycle $lifecycle,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(TrainingSession $session, User $student, string $method): TrainingSession
    {
        if ($session->isCash()) {
            throw new ApiException('cash_session', 'Esta aula é paga em dinheiro ao formador.', 409);
        }

        if (EscrowHold::query()->where('training_session_id', $session->id)->exists()) {
            return $session->refresh();
        }

        if ($session->state !== SessionState::PendingPayment) {
            throw new ApiException('payment_not_expected', 'Esta aula já não está à espera de pagamento.', 409);
        }

        if ($session->payment_due_at?->isPast()) {
            throw new ApiException('payment_window_expired', 'O prazo para pagar terminou. Publique um novo pedido.', 410);
        }

        $key = "charge:session:{$session->id}";
        $charge = $this->registerCharge($session, $student, $method, $key);

        if ($charge->status !== ProviderResult::SUCCEEDED) {
            $result = $this->provider->charge($session->amount, $student->phone, $method, $key, "Garimpo · {$session->title}");
            $charge->forceFill([
                'status' => $result->status,
                'external_reference' => $result->reference,
                'failure_reason' => $result->failureReason,
                'provider_payload' => $result->payload ?: null,
            ])->save();
        }

        $locked = DB::transaction(function () use ($session, $student, $charge) {
            /** @var TrainingSession $locked */
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($charge->status === ProviderResult::FAILED) {
                if ($locked->state === SessionState::PendingPayment) {
                    $this->lifecycle->transition($locked, SessionState::PaymentFailed, 'payment_failed', $student, ['reason' => $charge->failure_reason]);
                }

                return $locked;
            }

            if ($charge->status === ProviderResult::PENDING) {
                // Gateways assíncronos: a confirmação chega por webhook (fase do gateway real)
                return $locked;
            }

            $this->escrow->hold($locked, $charge);

            if ($locked->state === SessionState::PendingPayment) {
                $this->lifecycle->transition($locked, SessionState::Scheduled, 'payment_confirmed', $student, [
                    'charge_id' => $charge->id, 'amount' => $charge->amount, 'method' => $charge->method,
                ]);
            }

            $this->audit->record('payments.charged', $charge, ['amount' => $charge->amount], $student->id);

            return $locked;
        });

        // Fora da transação: a passagem a payment_failed fica gravada antes de responder com o erro
        if ($charge->status === ProviderResult::FAILED) {
            throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($charge->failure_reason ?? 'tente outro meio.'), 402);
        }

        return $locked;
    }

    private function registerCharge(TrainingSession $session, User $student, string $method, string $key): PaymentCharge
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
                'training_session_id' => $session->id,
                'student_id' => $student->id,
                'provider' => $this->provider->name(),
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
