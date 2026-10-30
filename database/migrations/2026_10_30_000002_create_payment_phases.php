<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 3.2: pagamento por fases nos orçamentos grandes (pagamento digital). O orçamento guarda as
| percentagens propostas; ao ser aceite criam-se as fases. Cada fase é paga para o escrow antes do trabalho dessa
| fase e libertada ao técnico (com a comissão) quando o cliente a confirma; a última segue a confirmação normal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('phases')->nullable()->after('execution_days')->comment('percentagens das fases, ex.: [30, 40, 30]');
        });

        Schema::create('payment_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->unsignedTinyInteger('sequence');
            $table->unsignedTinyInteger('percent');
            $table->unsignedInteger('amount')->comment('Kz');
            $table->string('status', 20)->default('pending')->comment('pending | due | paid | released');
            $table->foreignId('payment_charge_id')->nullable()->constrained('payment_charges');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['service_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_phases');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('phases');
        });
    }
};
