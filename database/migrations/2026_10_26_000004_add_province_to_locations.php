<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Província (as 21 da divisão de 2024). "Mesma cidade" para os pedidos de outro dia = mesma província.
| Os registos existentes ficam em Luanda (todas as zonas conhecidas são de Luanda).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_profiles', function (Blueprint $table) {
            $table->string('province', 40)->default('Luanda')->after('municipality')->index();
        });
        Schema::table('training_requests', function (Blueprint $table) {
            $table->string('province', 40)->default('Luanda')->after('municipality');
        });
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('province', 40)->default('Luanda')->after('municipality');
        });
    }

    public function down(): void
    {
        foreach (['trainer_profiles', 'training_requests', 'training_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'trainer_profiles') {
                    $table->dropIndex(['province']);
                }
                $table->dropColumn('province');
            });
        }
    }
};
