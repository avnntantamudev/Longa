<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 3.3: avaliações nos dois sentidos (cliente ↔ técnico), abertas depois de o pedido estar pago, e as
| sanções graduais do técnico por avaliações persistentemente más (aviso, suspensão, remoção revista pela equipa).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->foreignId('rater_id')->constrained('users');
            $table->foreignId('ratee_id')->constrained('users');
            $table->string('direction', 20)->comment('client_to_provider | provider_to_client');
            $table->unsignedTinyInteger('stars')->comment('1 a 5');
            $table->string('comment', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['service_request_id', 'rater_id']);
            $table->index(['ratee_id', 'direction', 'id']);
        });

        Schema::create('provider_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained();
            $table->string('level', 20)->comment('warning | suspension | removal');
            $table->string('status', 20)->default('active')->comment('active | ended | pending_review | confirmed | cancelled');
            $table->decimal('average', 3, 2)->comment('média das últimas avaliações quando a regra disparou');
            $table->unsignedSmallInteger('ratings_count');
            $table->string('reason', 255);
            $table->timestamp('ends_at')->nullable()->comment('fim da suspensão');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['provider_profile_id', 'level', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_sanctions');
        Schema::dropIfExists('ratings');
    }
};
