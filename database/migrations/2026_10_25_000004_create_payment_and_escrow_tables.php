<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranças ao gateway (mock nesta fase) e retenções em escrow, ligadas ao ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->string('provider', 30);
            $table->string('method', 30)->comment('multicaixa_express | reference');
            $table->string('kind', 10)->default('charge')->comment('charge | refund');
            $table->unsignedInteger('amount');
            $table->string('status', 20)->comment('pending | succeeded | failed');
            $table->string('external_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('failure_reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->index(['training_session_id', 'kind']);
        });

        Schema::create('escrow_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->unique()->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('trainer_profile_id')->constrained();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('commission');
            $table->unsignedInteger('trainer_net');
            $table->unsignedTinyInteger('commission_percent');
            $table->string('state', 20)->default('held')->comment('held | released | refunded | partially_refunded | disputed');
            $table->timestamp('expires_at')->nullable()->comment('Prazo máximo esperado para ter destino (reconciliação)');
            $table->foreignId('hold_transaction_id')->constrained('ledger_transactions');
            $table->foreignId('settlement_transaction_id')->nullable()->constrained('ledger_transactions');
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'expires_at']);
            $table->index(['trainer_profile_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_holds');
        Schema::dropIfExists('payment_charges');
    }
};
