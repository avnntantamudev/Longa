<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Remarcar uma aula de um pacote: uma das partes propõe outra hora (reschedule_to, reschedule_by) e a outra aceita
| ou recusa. reschedules_used conta, no pedido-contrato, quantas remarcações o pacote já usou (máximo nas Definições).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('reschedule_to')->nullable()->after('vip_only_until')->comment('aula: nova hora proposta');
            $table->foreignId('reschedule_by')->nullable()->after('reschedule_to')->comment('aula: quem propôs a nova hora')->constrained('users');
            $table->unsignedTinyInteger('reschedules_used')->default(0)->after('reschedule_by')->comment('pacote: remarcações já usadas');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reschedule_by');
            $table->dropColumn(['reschedule_to', 'reschedules_used']);
        });
    }
};
