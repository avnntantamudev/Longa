<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 20)->default('vip');
            $table->string('status', 20)->comment('pending | active | failed');
            $table->string('source', 20)->comment('electronic | cash | courtesy');
            $table->unsignedInteger('amount')->comment('Kz pagos (0 em cortesia)');
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('method', 30)->nullable()->comment('multicaixa_express | reference');
            $table->string('provider', 30)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('failure_reason')->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions');
            $table->foreignId('created_by')->nullable()->comment('Admin, em pagamentos em mão e cortesias')->constrained('users');
            $table->string('note', 255)->nullable();
            $table->json('reminders_sent')->nullable()->comment('Avisos de fim já enviados (dias antes)');
            $table->timestamps();
            $table->index(['trainer_profile_id', 'status', 'ends_at']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_subscriptions');
    }
};
