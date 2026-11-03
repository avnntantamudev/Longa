<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pacote de várias aulas: as datas pedidas pelo cliente ficam no pedido-contrato (lista ISO 8601, por ordem).
| As aulas (pedidos filhos) só se criam quando um técnico aceita o pacote.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->json('lesson_schedule')->nullable()->after('sessions')->comment('pacote: datas das aulas (ISO 8601), por ordem');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('lesson_schedule');
        });
    }
};
