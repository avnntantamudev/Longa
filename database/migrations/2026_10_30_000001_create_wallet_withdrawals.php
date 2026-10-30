<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 3.1: carteira do técnico.
|  - withdrawals: pedidos de levantamento (o valor fica retido no livro-razão até a equipa pagar ou recusar);
|  - payment_charges.service_request_id passa a opcional: as recargas da carteira não pertencem a um pedido.
| Os movimentos continuam a ser as linhas imutáveis do livro-razão (ledger_entries); o saldo é sempre a soma delas.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained();
            $table->unsignedInteger('amount')->comment('Kz');
            $table->string('destination', 120)->comment('IBAN ou número Multicaixa Express');
            $table->string('status', 20)->default('requested')->comment('requested | paid | rejected');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('payout_reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['provider_profile_id', 'status']);
        });

        Schema::table('payment_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('service_request_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');

        Schema::table('payment_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('service_request_id')->nullable(false)->change();
        });
    }
};
