<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Ledger;

use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerAccountBalance;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Única porta de escrita no livro-razão.
 *
 * post() garante, numa só transação de base de dados:
 *  1. idempotência: a mesma chave devolve a transação já registada (e recusa se os lançamentos forem outros);
 *  2. equilíbrio: valores inteiros ≠ 0 que somam 0 (verificado antes e depois de gravar);
 *  3. bloqueio das contas envolvidas por ordem crescente de id (sem deadlocks entre operações concorrentes);
 *  4. lançamentos imutáveis + atualização da cache de saldos.
 */
class LedgerService
{
    /**
     * @param  array<string, int>  $lines  código da conta => valor em Kz com sinal
     * @param  array<string, mixed>  $metadata
     *
     * @throws LedgerException
     */
    public function post(
        string $type,
        string $idempotencyKey,
        array $lines,
        ?Model $reference = null,
        array $metadata = [],
        ?int $createdBy = null,
        ?LedgerTransaction $reverses = null,
    ): LedgerTransaction {
        $lines = $this->normalize($lines);

        $existing = $this->findExisting($idempotencyKey, $lines);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($type, $idempotencyKey, $lines, $reference, $metadata, $createdBy, $reverses) {
                $accounts = $this->lockAccounts(array_keys($lines));

                $debit = array_sum(array_filter($lines, fn (int $v) => $v > 0));

                $transaction = LedgerTransaction::query()->create([
                    'type' => $type,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'posted',
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference?->getKey(),
                    'reverses_transaction_id' => $reverses?->id,
                    'total_debit' => $debit,
                    'total_credit' => $debit,
                    'metadata' => $metadata === [] ? null : $metadata,
                    'created_by' => $createdBy,
                ]);

                foreach ($lines as $code => $amount) {
                    $account = $accounts[$code];
                    $entry = LedgerEntry::query()->create([
                        'ledger_transaction_id' => $transaction->id,
                        'ledger_account_id' => $account->id,
                        'amount' => $amount,
                    ]);

                    LedgerAccountBalance::query()->whereKey($account->id)->update([
                        'balance' => DB::raw('balance + '.$amount),
                        'last_entry_id' => $entry->id,
                        'updated_at' => now(),
                    ]);
                }

                // Verificação final na base de dados, dentro da mesma transação
                $sum = (int) LedgerEntry::query()->where('ledger_transaction_id', $transaction->id)->sum('amount');
                if ($sum !== 0) {
                    throw new LedgerException("Transação {$transaction->id} desequilibrada ({$sum} Kz).");
                }

                return $transaction->load('entries.account');
            });
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com outro processo que usou a mesma chave: devolve a transação vencedora
            return $this->findExisting($idempotencyKey, $lines) ?? throw $e;
        }
    }

    /**
     * Estorno: nova transação com os mesmos lançamentos de sinal contrário.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reverse(LedgerTransaction $transaction, string $idempotencyKey, array $metadata = [], ?int $createdBy = null): LedgerTransaction
    {
        $lines = [];
        foreach ($transaction->entries()->with('account')->get() as $entry) {
            $lines[$entry->account->code] = ($lines[$entry->account->code] ?? 0) - $entry->amount;
        }

        return $this->post('reversal', $idempotencyKey, $lines, $transaction->reference, $metadata, $createdBy, $transaction);
    }

    /**
     * Saldo atual (cache) de uma conta; 0 se a conta ainda não existe.
     */
    public function balance(string $code): int
    {
        return (int) LedgerAccountBalance::query()
            ->whereHas('account', fn ($q) => $q->where('code', $code))
            ->value('balance');
    }

    /**
     * Saldo calculado diretamente dos lançamentos (fonte de verdade, usado pela reconciliação).
     */
    public function balanceFromEntries(string $code): int
    {
        return (int) LedgerEntry::query()
            ->whereHas('account', fn ($q) => $q->where('code', $code))
            ->sum('amount');
    }

    public function trainerAvailable(TrainerProfile $trainer): int
    {
        return $this->balance(Accounts::trainerAvailable($trainer));
    }

    /**
     * @param  array<string, int>  $lines
     * @return array<string, int>
     */
    private function normalize(array $lines): array
    {
        if ($lines === []) {
            throw new LedgerException('Transação sem lançamentos.');
        }

        foreach ($lines as $code => $amount) {
            $this->assertAmount($code, $amount);
        }

        if (array_sum($lines) !== 0) {
            throw new LedgerException('Transação desequilibrada: os lançamentos têm de somar 0 (soma = '.array_sum($lines).').');
        }

        ksort($lines);

        return $lines;
    }

    /**
     * Parâmetro int com strict_types: um float (ex.: resultado de uma divisão) dá TypeError aqui.
     */
    private function assertAmount(string $code, int $amount): void
    {
        if ($amount === 0) {
            throw new LedgerException("Valor inválido na conta {$code}: os lançamentos são inteiros diferentes de 0.");
        }
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function findExisting(string $idempotencyKey, array $lines): ?LedgerTransaction
    {
        $existing = LedgerTransaction::query()->where('idempotency_key', $idempotencyKey)->with('entries.account')->first();

        if ($existing === null) {
            return null;
        }

        $recorded = [];
        foreach ($existing->entries as $entry) {
            $recorded[$entry->account->code] = $entry->amount;
        }
        ksort($recorded);

        if ($recorded !== $lines) {
            throw new LedgerException("Chave de idempotência {$idempotencyKey} já usada com outros lançamentos.");
        }

        return $existing;
    }

    /**
     * Cria (se preciso) e bloqueia as contas por ordem crescente de id.
     *
     * @param  list<string>  $codes
     * @return array<string, LedgerAccount>
     */
    private function lockAccounts(array $codes): array
    {
        foreach ($codes as $code) {
            [$type, $ownerId] = Accounts::parse($code);
            LedgerAccount::query()->insertOrIgnore([
                'code' => $code,
                'type' => $type,
                'owner_type' => $ownerId === null ? null : (new TrainerProfile)->getMorphClass(),
                'owner_id' => $ownerId,
                'currency' => 'AOA',
                'created_at' => now(),
            ]);
        }

        $accounts = LedgerAccount::query()->whereIn('code', $codes)->orderBy('id')->get()->keyBy('code');

        LedgerAccountBalance::query()->insertOrIgnore(
            $accounts->map(fn (LedgerAccount $a) => ['ledger_account_id' => $a->id, 'balance' => 0, 'updated_at' => now()])->values()->all(),
        );

        // Bloqueio determinístico (ORDER BY id) das linhas de saldo das contas envolvidas
        LedgerAccountBalance::query()->whereIn('ledger_account_id', $accounts->pluck('id'))->orderBy('ledger_account_id')->lockForUpdate()->get();

        /** @var array<string, LedgerAccount> */
        return $accounts->all();
    }
}
