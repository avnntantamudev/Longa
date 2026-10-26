<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->comment('Como o formador escreveu');
            $table->string('normalized', 100)->index()->comment('Slug, para agrupar sugestões iguais');
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected');
            $table->foreignId('skill_id')->nullable()->comment('Área final (nova ou existente)')->constrained();
            $table->string('review_note', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['trainer_profile_id', 'normalized']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_suggestions');
    }
};
