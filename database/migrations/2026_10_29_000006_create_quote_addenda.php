<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2.5: adendas ao orçamento aceite (trabalho extra). O técnico submete, o cliente aprova antes de o
| trabalho ser feito; quando o total das adendas passa o limite percentual do orçamento original, a equipa revê.
| Aprovada, o valor do pedido sobe (no pagamento digital, o cliente paga a adenda e o valor junta-se ao escrow).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->foreignId('quote_id')->constrained();
            $table->foreignId('provider_profile_id')->constrained();
            $table->string('description', 1000);
            $table->unsignedInteger('amount')->comment('Kz');
            $table->unsignedSmallInteger('percent_of_quote')->comment('adendas aprovadas + esta, em % do orçamento original');
            $table->string('status', 20)->default('pending_client')
                ->comment('pending_client | pending_team | approved | paid | rejected');
            $table->boolean('team_review')->default(false)->comment('passou o limite: precisa da equipa');
            $table->timestamp('client_decided_at')->nullable();
            $table->string('client_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['service_request_id', 'status']);
            $table->index(['status', 'team_review']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_addenda');
    }
};
