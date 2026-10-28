<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Support\Audit\AuditLogger;

/**
 * Comissões de aulas pagas em dinheiro (regra do cliente, docs/decisions.md):
 *  - registar: o formador recebeu o valor em mão, logo deve a comissão ao BISCA;
 *  - compensar: se tiver saldo disponível (aulas pagas eletronicamente), a dívida é descontada;
 *  - receber: o admin marca o dinheiro da comissão como recebido.
 */
class CommissionDebts
{
    public function __construct(private readonly LedgerService $ledger, private readonly AuditLogger $audit) {}

    /**
     * Devolve null quando a comissão arredonda para 0 Kz (aulas de 1 a 3 Kz): não há dívida a registar.
     */
    public function register(TrainingSession $session, ?User $actor, string $reason): ?LedgerTransaction
    {
        if ($session->commission === 0) {
            return null;
        }

        return $this->ledger->post('cash_commission_due', "cash:due:session:{$session->id}", [
            Accounts::PLATFORM_REVENUE => $session->commission,
            Accounts::trainerCommissionDue($session->trainer_profile_id) => -$session->commission,
        ], $session, ['reason' => $reason, 'amount_paid_in_cash' => $session->amount], $actor?->id);
    }

    /**
     * Desconta a dívida do saldo disponível (até onde der). Devolve o valor compensado.
     */
    public function offset(int $trainerProfileId, string $trigger): int
    {
        // Já compensado por este evento (os saldos podem ter mudado desde então)
        if (LedgerTransaction::query()->where('idempotency_key', "cash:offset:{$trainerProfileId}:{$trigger}")->exists()) {
            return 0;
        }

        $dueCode = Accounts::trainerCommissionDue($trainerProfileId);
        $availableCode = Accounts::trainerAvailable($trainerProfileId);

        // Saldos lidos com as contas bloqueadas: duas compensações em paralelo nunca descontam a mais
        return $this->ledger->withLockedAccounts([$availableCode, $dueCode], function () use ($dueCode, $availableCode, $trainerProfileId, $trigger) {
            $amount = min(-$this->ledger->balance($dueCode), $this->ledger->balance($availableCode));

            if ($amount <= 0) {
                return 0;
            }

            $this->ledger->post('commission_offset', "cash:offset:{$trainerProfileId}:{$trigger}", [
                $availableCode => -$amount,
                $dueCode => $amount,
            ], null, ['trigger' => $trigger]);

            return $amount;
        });
    }

    /**
     * O admin recebeu do formador (parte de) a comissão em dívida.
     */
    public function receive(int $trainerProfileId, int $amount, string $idempotencyKey, User $admin, ?string $note): LedgerTransaction
    {
        $due = -$this->ledger->balance(Accounts::trainerCommissionDue($trainerProfileId));

        $existing = LedgerTransaction::query()->where('idempotency_key', "cash:received:{$idempotencyKey}")->first();
        if ($existing === null && ($amount <= 0 || $amount > $due)) {
            throw new LedgerException("Valor inválido: {$amount} Kz (dívida atual: {$due} Kz).");
        }

        $transaction = $this->ledger->post('cash_commission_received', "cash:received:{$idempotencyKey}", [
            Accounts::CASH_SETTLEMENT => -$amount,
            Accounts::trainerCommissionDue($trainerProfileId) => $amount,
        ], null, ['note' => $note, 'trainer_profile_id' => $trainerProfileId], $admin->id);

        $this->audit->record('payments.cash_commission_received', $transaction, ['amount' => $amount, 'trainer_profile_id' => $trainerProfileId], $admin->id);

        return $transaction;
    }

    public function outstanding(int $trainerProfileId): int
    {
        return -$this->ledger->balance(Accounts::trainerCommissionDue($trainerProfileId));
    }
}
