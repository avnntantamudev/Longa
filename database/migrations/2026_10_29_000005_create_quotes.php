<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2.5 (por orçamento):
|  - service_requests.visit_fee: taxa de visita da categoria no envio (paga em mão ao técnico no diagnóstico e
|    descontada no serviço se o orçamento for aceite);
|  - quotes: orçamentos do técnico (mão de obra, materiais, prazo, validade), com revisões. Cada orçamento guarda o
|    técnico, para a opção futura de vários orçamentos por pedido.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedInteger('visit_fee')->default(0)->after('discount_percent')->comment('Kz, taxa de visita (orçamentos)');
            $table->timestamp('diagnosis_started_at')->nullable()->after('on_the_way_at');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->foreignId('provider_profile_id')->constrained();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->unsignedInteger('labour_amount')->comment('Kz, mão de obra');
            $table->unsignedInteger('materials_amount')->default(0)->comment('Kz, materiais');
            $table->json('materials')->nullable()->comment('lista: [{descricao, quantidade, valor}]');
            $table->unsignedInteger('total')->comment('mão de obra + materiais');
            $table->unsignedInteger('visit_fee_deducted')->default(0)->comment('taxa de visita já paga, descontada no valor a pagar');
            $table->unsignedSmallInteger('execution_days')->comment('prazo de execução (dias)');
            $table->text('notes')->nullable();
            $table->timestamp('valid_until');
            $table->string('status', 20)->default('sent')->comment('sent | accepted | refused | revision_requested | expired | superseded');
            $table->boolean('outside_reference')->default(false)->comment('muito fora do intervalo de referência da categoria');
            $table->string('client_note', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['service_request_id', 'status']);
            $table->index(['status', 'valid_until']);
            $table->index(['outside_reference', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['visit_fee', 'diagnosis_started_at']);
        });
    }
};
