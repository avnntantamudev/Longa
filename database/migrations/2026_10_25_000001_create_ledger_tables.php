<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Livro-razão de dupla entrada (Fase 3). Ver docs/ledger.md.
 *
 *  - Valores em Kz inteiros (unidade mínima: 1 Kz), com sinal: a soma dos lançamentos de cada
 *    transação é 0 e a soma de todas as contas é 0.
 *  - ledger_transactions e ledger_entries são só de acréscimo: triggers rejeitam UPDATE e DELETE.
 *    Correções fazem-se com transações de estorno (reverses_transaction_id).
 *  - ledger_account_balances é uma cache reconstruível (longa:ledger:rebuild), atualizada só pelo
 *    LedgerService dentro da mesma transação, e verificada pela reconciliação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()->comment('ex.: escrow, trainer_available:12');
            $table->string('type', 40)->index()->comment('funds_in | escrow | platform_revenue | refunds_payable | cash_settlement | trainer_available | trainer_commission_due');
            $table->nullableMorphs('owner');
            $table->char('currency', 3)->default('AOA');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ledger_account_balances', function (Blueprint $table) {
            $table->foreignId('ledger_account_id')->primary()->constrained();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('idempotency_key', 120)->unique();
            $table->string('status', 20)->default('posted');
            $table->nullableMorphs('reference');
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('ledger_transactions');
            $table->unsignedBigInteger('total_debit')->comment('Soma dos lançamentos positivos');
            $table->unsignedBigInteger('total_credit')->comment('Soma dos lançamentos negativos (em valor absoluto)');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6)->useCurrent();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained();
            $table->foreignId('ledger_account_id')->constrained();
            $table->bigInteger('amount')->comment('Kz com sinal: + débito, − crédito');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['ledger_account_id', 'id']);
        });

        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_balanced CHECK (total_debit = total_credit AND total_debit > 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_not_zero CHECK (amount <> 0)');

        foreach (['ledger_transactions', 'ledger_entries'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = strtolower("{$table}_no_{$operation}");
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only: post a reversal instead'");
            }
        }
    }

    public function down(): void
    {
        foreach (['ledger_transactions', 'ledger_entries'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation}");
            }
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_account_balances');
        // Auto-referência: libertar antes de apagar a tabela
        Schema::table('ledger_transactions', fn (Blueprint $t) => $t->dropForeign(['reverses_transaction_id']));
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
