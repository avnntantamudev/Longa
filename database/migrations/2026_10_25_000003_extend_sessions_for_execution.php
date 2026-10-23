<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: duração e método de pagamento (pedido e aula), marcos de execução da aula e linha do tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->default(60)->after('scheduled_at');
            $table->string('payment_method', 20)->default('electronic')->after('trainer_net')->comment('electronic | cash');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->default(60)->after('scheduled_at');
            $table->dateTime('ends_at')->nullable()->after('duration_minutes');
            $table->string('payment_method', 20)->default('electronic')->after('trainer_net');
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('awaiting_since')->nullable()->comment('Início da janela de confirmação/disputa');
            $table->timestamp('no_show_contest_until')->nullable();
            $table->timestamp('cash_confirmed_at')->nullable();
            $table->index(['state', 'ends_at']);
            $table->index(['state', 'payment_due_at']);
        });

        Schema::create('session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained();
            $table->string('type', 40);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->foreignId('actor_id')->nullable()->comment('null = sistema (job/regra automática)')->constrained('users');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['training_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_events');

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropIndex(['state', 'ends_at']);
            $table->dropIndex(['state', 'payment_due_at']);
            $table->dropColumn(['duration_minutes', 'ends_at', 'payment_method', 'payment_due_at', 'started_at', 'ended_at', 'confirmed_at', 'awaiting_since', 'no_show_contest_until', 'cash_confirmed_at']);
        });

        Schema::table('training_requests', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'payment_method']);
        });
    }
};
