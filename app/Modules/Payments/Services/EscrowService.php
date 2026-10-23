<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Jobs\ExecuteRefund;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\TrainingSession;
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
 *  settle()  escrow → formador (líquido) + LONGA (comissão sobre a parte do formador) + reembolso ao aluno
 *            Cobre libertação total, reembolso total e reembolso parcial com um único cálculo.
 *
 * Cada operação é idempotente (chave derivada da aula) e bloqueia o hold (SELECT … FOR UPDATE):
 * duas liquidações simultâneas da mesma aula nunca movem o dinheiro duas vezes.
 */
class EscrowService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function hold(TrainingSession $session, PaymentCharge $charge): EscrowHold
    {
        return DB::transaction(function () use ($session, $charge) {
            $existing = EscrowHold::query()->where('training_session_id', $session->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->ledger->post('escrow_hold', "escrow:hold:session:{$session->id}", [
                Accounts::ESCROW => $session->amount,
                Accounts::FUNDS_IN => -$session->amount,
            ], $session, ['charge_id' => $charge->id, 'reference' => $charge->external_reference]);

            $hold = EscrowHold::query()->create([
                'training_session_id' => $session->id,
                'student_id' => $session->student_id,
                'trainer_profile_id' => $session->trainer_profile_id,
                'amount' => $session->amount,
                'commission' => $session->commission,
                'trainer_net' => $session->trainer_net,
                'commission_percent' => (int) config('longa.requests.commission_percent'),
                // Prazo máximo esperado para a retenção ter destino (alerta da reconciliação se ultrapassado)
                'expires_at' => $session->ends_at?->copy()->addHours((int) config('longa.session_rules.hold_max_days') * 24),
                'hold_transaction_id' => $transaction->id,
            ]);

            $this->audit->record('escrow.held', $hold, ['amount' => $hold->amount, 'session_id' => $session->id]);

            return $hold;
        });
    }

    /**
     * Liquidação: o formador fica com $trainerGross (antes da comissão) e o resto volta ao aluno.
     *  - libertar tudo: $trainerGross = valor total
     *  - reembolso total: 0
     *  - reembolso parcial: qualquer valor entre 0 e o total
     *
     * @param  EscrowHold::RELEASED|EscrowHold::REFUNDED|EscrowHold::PARTIALLY_REFUNDED  $outcome
     * @param  array<string, mixed>  $metadata
     */
    public function settle(TrainingSession $session, int $trainerGross, string $outcome, string $reason, ?User $actor = null, array $metadata = []): EscrowHold
    {
        $hold = DB::transaction(function () use ($session, $trainerGross, $outcome, $reason, $actor, $metadata) {
            /** @var EscrowHold $hold */
            $hold = EscrowHold::query()->where('training_session_id', $session->id)->lockForUpdate()->firstOrFail();

            // Idempotência: já liquidado com o mesmo destino
            if (! $hold->isActive()) {
                if ($hold->state === $outcome) {
                    return $hold;
                }

                throw new LedgerException("Retenção da aula {$session->id} já liquidada como {$hold->state}.");
            }

            if ($trainerGross < 0 || $trainerGross > $hold->amount) {
                throw new LedgerException("Parte do formador inválida: {$trainerGross} Kz de {$hold->amount} Kz.");
            }

            // Libertação total usa os valores gravados na retenção; partes usam a mesma percentagem
            $split = $trainerGross === $hold->amount
                ? ['commission' => $hold->commission, 'trainer_net' => $hold->trainer_net]
                : Commission::split($trainerGross, $hold->commission_percent);
            $refund = $hold->amount - $trainerGross;

            $lines = array_filter([
                Accounts::ESCROW => -$hold->amount,
                Accounts::trainerAvailable($hold->trainer_profile_id) => $split['trainer_net'],
                Accounts::PLATFORM_REVENUE => $split['commission'],
                Accounts::REFUNDS_PAYABLE => $refund,
            ], fn (int $v) => $v !== 0);

            $transaction = $this->ledger->post(
                $refund === 0 ? 'escrow_release' : ($trainerGross === 0 ? 'refund' : 'refund_partial'),
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
                'trainer_net' => $split['trainer_net'], 'commission' => $split['commission'], 'refund' => $refund, 'reason' => $reason,
            ], $actor?->id);

            return $hold;
        });

        // Reembolso no gateway: depois do commit e fora de transações (chamada de rede), via fila
        if ($hold->refunded_amount > 0 && $hold->wasChanged('state')) {
            ExecuteRefund::dispatch($hold->id);
        }

        return $hold;
    }

    public function markDisputed(TrainingSession $session): void
    {
        EscrowHold::query()->where('training_session_id', $session->id)->where('state', EscrowHold::HELD)->update(['state' => EscrowHold::DISPUTED]);
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

        $charge = PaymentCharge::query()->where('training_session_id', $hold->training_session_id)->where('kind', 'charge')->where('status', 'succeeded')->first();
        if ($charge === null) {
            Log::error('escrow.refund_without_charge', ['hold_id' => $hold->id]);

            return;
        }

        $result = $this->provider->refund((string) $charge->external_reference, $hold->refunded_amount, $key);

        PaymentCharge::query()->updateOrCreate(['idempotency_key' => $key], [
            'training_session_id' => $hold->training_session_id,
            'student_id' => $hold->student_id,
            'provider' => $this->provider->name(),
            'method' => $charge->method,
            'kind' => 'refund',
            'amount' => $hold->refunded_amount,
            'status' => $result->status,
            'external_reference' => $result->reference,
            'failure_reason' => $result->failureReason,
            'provider_payload' => $result->payload ?: null,
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
