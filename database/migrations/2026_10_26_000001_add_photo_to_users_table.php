<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo', 40)->nullable()->after('email')
                ->comment('Nome aleatório da foto (photos/{photo}-512.webp e -128.webp no disco local)');
            $table->timestamp('photo_updated_at')->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo', 'photo_updated_at']);
        });
    }
};
