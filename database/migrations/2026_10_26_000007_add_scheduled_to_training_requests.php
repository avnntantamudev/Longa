<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_requests', function (Blueprint $table) {
            $table->boolean('scheduled')->default(false)->after('state')
                ->comment('Aula noutro dia: formadores a 5 km + VIP da província, aberto até X h antes');
        });
    }

    public function down(): void
    {
        Schema::table('training_requests', function (Blueprint $table) {
            $table->dropColumn('scheduled');
        });
    }
};
