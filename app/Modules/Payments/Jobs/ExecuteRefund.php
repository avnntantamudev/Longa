<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\EscrowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executa no gateway um reembolso já decidido no ledger (refunds_payable). Corre depois do commit,
 * na fila "payments", fora de qualquer transação de base de dados. Seguro de repetir (idempotente).
 */
class ExecuteRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  int|null  $amount  devolução parcial (aulas de um pacote); null = o reembolso da liquidação
     * @param  string|null  $key  chave de idempotência do pagamento dessa devolução
     */
    public function __construct(public readonly int $holdId, public readonly ?int $amount = null, public readonly ?string $key = null)
    {
        $this->onQueue('payments');
        $this->afterCommit();
    }

    public function handle(EscrowService $escrow): void
    {
        $hold = EscrowHold::query()->find($this->holdId);

        if ($hold !== null && ($this->amount ?? $hold->refunded_amount) > 0) {
            $escrow->payRefund($hold, $this->amount, $this->key);
        }
    }
}
