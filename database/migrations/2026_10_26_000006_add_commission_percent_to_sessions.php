<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('commission_percent')->default(15)->after('trainer_net')
                ->comment('Comissão aplicada ao formador ao aceitar (reduzida se for VIP)');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
