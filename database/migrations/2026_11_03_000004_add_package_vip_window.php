<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pacotes: até vip_only_until o pacote só é oferecido a técnicos VIP; depois abre a todos os elegíveis
| (com "só VIP" ligado nas Definições, a janela dura até ao fecho do pedido).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('vip_only_until')->nullable()->after('next_wave_at')->comment('pacote: só técnicos VIP até esta hora');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('vip_only_until');
        });
    }
};
