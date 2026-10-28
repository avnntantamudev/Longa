<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Jobs\ExecuteRefund;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escrow de uma aula sobre o livro-razão (docs/ledger.md).
 *
 *  hold()    entrada externa → escrow (após cobrança confirmada pelo gateway)
 *  settle()  escrow → formador (líquido) + BISCA (comissão sobre a parte do formador) + reembolso ao aluno
 *            Cobre libertação total, reembolso total e reembolso parcial com um único cálculo.
 *
 * Cada operação é idempotente (chave derivada da aula) e bloqueia o hold (SELECT … FOR UPDATE):
 * duas liquidações simultâneas da mesma aula nunca movem o dinheiro duas vezes.
 */
class EscrowService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentProvider $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function hold(ServiceJob $session, PaymentCharge $charge): EscrowHold
    {
        return DB::transaction(function () use ($session, $charge) {
            $existing = EscrowHold::query()->where('service_job_id', $session->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->ledger->post('escrow_hold', "escrow:hold:session:{$session->id}", [
                Accounts::ESCROW => $session->amount,
                Accounts::FUNDS_IN => -$session->amount,
            ], $session, ['charge_id' => $charge->id, 'reference' => $charge->external_reference]);

            $hold = EscrowHold::query()->create([
                'service_job_id' => $session->id,
                'client_id' => $session->client_id,
                'provider_profile_id' => $session->provider_profile_id,
                'amount' => $session->amount,
                'commission' => $session->commission,
                'provider_net' => $session->provider_net,
                'commission_percent' => $session->commission_percent,
                // Prazo máximo esperado para a retenção ter destino (alerta da reconciliação se ultrapassado)
                'expires_at' => $session->ends_at?->copy()->addHours((int) config('longa.session_rules.hold_max_days') * 24),
                'hold_transaction_id' => $transaction->id,
            ]);

            $this->audit->record('escrow.held', $hold, ['amount' => $hold->amount, 'session_id' => $session->id]);

            return $hold;
        });
    }

    /**
     * Liquidação: o formador fica com $providerGross (antes da comissão) e o resto volta ao aluno.
     *  - libertar tudo: $providerGross = valor total
     *  - reembolso total: 0
     *  - reembolso parcial: qualquer valor entre 0 e o total
     *
     * @param  EscrowHold::RELEASED|EscrowHold::REFUNDED|EscrowHold::PARTIALLY_REFUNDED  $outcome
     * @param  array<string, mixed>  $metadata
     */
    public function settle(ServiceJob $session, int $providerGross, string $outcome, string $reason, ?User $actor = null, array $metadata = []): EscrowHold
    {
        $hold = DB::transaction(function () use ($session, $providerGross, $outcome, $reason, $actor, $metadata) {
            /** @var EscrowHold $hold */
            $hold = EscrowHold::query()->where('service_job_id', $session->id)->lockForUpdate()->firstOrFail();

            // Idempotência: já liquidado com o mesmo destino
            if (! $hold->isActive()) {
                if ($hold->state === $outcome) {
                    return $hold;
                }

                throw new LedgerException("Retenção da aula {$session->id} já liquidada como {$hold->state}.");
            }

            if ($providerGross < 0 || $providerGross > $hold->amount) {
                throw new LedgerException("Parte do formador inválida: {$providerGross} Kz de {$hold->amount} Kz.");
            }

            // Libertação total usa os valores gravados na retenção; partes usam a mesma percentagem
            $split = $providerGross === $hold->amount
                ? ['commission' => $hold->commission, 'provider_net' => $hold->provider_net]
                : Commission::split($providerGross, $hold->commission_percent);
            $refund = $hold->amount - $providerGross;

            $lines = array_filter([
                Accounts::ESCROW => -$hold->amount,
                Accounts::providerAvailable($hold->provider_profile_id) => $split['provider_net'],
                Accounts::PLATFORM_REVENUE => $split['commission'],
                Accounts::REFUNDS_PAYABLE => $refund,
            ], fn (int $v) => $v !== 0);

            $transaction = $this->ledger->post(
                $refund === 0 ? 'escrow_release' : ($providerGross === 0 ? 'refund' : 'refund_partial'),
                "escrow:settle:session:{$session->id}",
                $lines,
                $session,
                [...$metadata, 'reason' => $reason, 'outcome' => $outcome],
                $actor?->id,
            );

            $hold->forceFill([
                'state' => $outcome,
                'settlement_transaction_id' => $transaction->id,
                'refunded_amount' => $refund,
                'settled_at' => now(),
            ])->save();

            $this->audit->record("escrow.{$outcome}", $hold, [
                'provider_net' => $split['provider_net'], 'commission' => $split['commission'], 'refund' => $refund, 'reason' => $reason,
            ], $actor?->id);

            return $hold;
        });

        // Reembolso no gateway: depois do commit e fora de transações (chamada de rede), via fila
        if ($hold->refunded_amount > 0 && $hold->wasChanged('state')) {
            ExecuteRefund::dispatch($hold->id);
        }

        return $hold;
    }

    public function markDisputed(ServiceJob $session): void
    {
        EscrowHold::query()->where('service_job_id', $session->id)->where('state', EscrowHold::HELD)->update(['state' => EscrowHold::DISPUTED]);
    }

    /**
     * Executa o reembolso no gateway e move refunds_payable → funds_in. Se o gateway falhar, o valor
     * fica em refunds_payable (visível na reconciliação) e pode ser repetido com segurança.
     */
    public function payRefund(EscrowHold $hold): void
    {
        $key = "refund:paid:hold:{$hold->id}";

        if (LedgerTransaction::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $charge = PaymentCharge::query()->where('service_job_id', $hold->service_job_id)->where('kind', 'charge')->where('status', 'succeeded')->first();
        if ($charge === null) {
            Log::error('escrow.refund_without_charge', ['hold_id' => $hold->id]);

            return;
        }

        $result = $this->gateway->refund((string) $charge->external_reference, $hold->refunded_amount, $key);

        PaymentCharge::query()->updateOrCreate(['idempotency_key' => $key], [
            'service_job_id' => $hold->service_job_id,
            'client_id' => $hold->client_id,
            'gateway' => $this->gateway->name(),
            'method' => $charge->method,
            'kind' => 'refund',
            'amount' => $hold->refunded_amount,
            'status' => $result->status,
            'external_reference' => $result->reference,
            'failure_reason' => $result->failureReason,
            'gateway_payload' => $result->payload ?: null,
        ]);

        if (! $result->succeeded()) {
            Log::warning('escrow.refund_pending', ['hold_id' => $hold->id, 'status' => $result->status]);

            return;
        }

        $this->ledger->post('refund_paid', $key, [
            Accounts::REFUNDS_PAYABLE => -$hold->refunded_amount,
            Accounts::FUNDS_IN => $hold->refunded_amount,
        ], $hold->session, ['refund_reference' => $result->reference]);
    }
}
