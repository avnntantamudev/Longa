<?php

declare(strict_types=1);

use App\Modules\Requests\Services\Commission;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->ledger = app(LedgerService::class));

it('regista uma transação equilibrada e atualiza os saldos', function () {
    $tx = $this->ledger->post('escrow_hold', 'hold:1', [Accounts::ESCROW => 25000, Accounts::FUNDS_IN => -25000]);

    expect($tx->total_debit)->toBe(25000)
        ->and($tx->entries)->toHaveCount(2)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(25000)
        ->and($this->ledger->balance(Accounts::FUNDS_IN))->toBe(-25000)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0);
});

it('recusa transações desequilibradas, vazias ou com valores zero', function (array $lines) {
    expect(fn () => $this->ledger->post('x', 'bad:'.md5(serialize($lines)), $lines))->toThrow(LedgerException::class);
    expect(LedgerTransaction::query()->count())->toBe(0);
})->with([
    'desequilibrada' => [[Accounts::ESCROW => 100, Accounts::FUNDS_IN => -99]],
    'vazia' => [[]],
    'zero' => [[Accounts::ESCROW => 0, Accounts::FUNDS_IN => 0]],
]);

it('é idempotente: a mesma chave não duplica e recusa lançamentos diferentes', function () {
    $a = $this->ledger->post('escrow_hold', 'hold:1', [Accounts::ESCROW => 5000, Accounts::FUNDS_IN => -5000]);
    $b = $this->ledger->post('escrow_hold', 'hold:1', [Accounts::FUNDS_IN => -5000, Accounts::ESCROW => 5000]);

    expect($b->id)->toBe($a->id)
        ->and(LedgerTransaction::query()->count())->toBe(1)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(5000);

    expect(fn () => $this->ledger->post('escrow_hold', 'hold:1', [Accounts::ESCROW => 6000, Accounts::FUNDS_IN => -6000]))
        ->toThrow(LedgerException::class, 'já usada com outros lançamentos');
});

it('corrige com estornos e nunca altera lançamentos', function () {
    $tx = $this->ledger->post('escrow_hold', 'hold:1', [Accounts::ESCROW => 5000, Accounts::FUNDS_IN => -5000]);

    $reversal = $this->ledger->reverse($tx, 'reverse:hold:1', ['reason' => 'teste']);

    expect($reversal->reverses_transaction_id)->toBe($tx->id)
        ->and($this->ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(4);
});

it('a base de dados rejeita UPDATE e DELETE no ledger', function () {
    $tx = $this->ledger->post('escrow_hold', 'hold:1', [Accounts::ESCROW => 5000, Accounts::FUNDS_IN => -5000]);

    expect(fn () => DB::table('ledger_entries')->update(['amount' => 1]))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('ledger_entries')->delete())->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('ledger_transactions')->where('id', $tx->id)->update(['type' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => $tx->delete())->toThrow(LogicException::class);
});

it('a base de dados rejeita totais declarados desequilibrados', function () {
    expect(fn () => DB::table('ledger_transactions')->insert([
        'type' => 'x', 'idempotency_key' => 'manual', 'status' => 'posted', 'total_debit' => 10, 'total_credit' => 9,
    ]))->toThrow(QueryException::class);
});

it('a cache de saldos coincide com a soma dos lançamentos', function () {
    $this->ledger->post('escrow_hold', 'h1', [Accounts::ESCROW => 25000, Accounts::FUNDS_IN => -25000]);
    $this->ledger->post('escrow_release', 'r1', [Accounts::ESCROW => -25000, Accounts::trainerAvailable(7) => 21250, Accounts::PLATFORM_REVENUE => 3750]);

    foreach ([Accounts::ESCROW, Accounts::FUNDS_IN, Accounts::PLATFORM_REVENUE, Accounts::trainerAvailable(7)] as $code) {
        expect($this->ledger->balance($code))->toBe($this->ledger->balanceFromEntries($code));
    }
    expect($this->ledger->balance(Accounts::trainerAvailable(7)))->toBe(21250);
});

it('a comissão + o valor do formador = total, em valores difíceis', function (int $amount) {
    $split = Commission::split($amount, 15);

    expect($split['commission'] + $split['trainer_net'])->toBe($amount)
        ->and($split['commission'])->toBe(intdiv($amount * 15 + 50, 100))
        ->and($split['commission'])->toBeGreaterThanOrEqual(0)
        ->and($split['trainer_net'])->toBeGreaterThanOrEqual(0);
})->with([1, 3, 7, 999, 25000, 450000, 3333, 2010, 1_999_999]);

it('recusa valores não inteiros (ex.: resultado de uma divisão)', function () {
    /** @phpstan-ignore argument.type (teste de defesa em tempo de execução) */
    expect(fn () => $this->ledger->post('x', 'float', [Accounts::ESCROW => 2500.5, Accounts::FUNDS_IN => -2500.5]))->toThrow(TypeError::class);
});
