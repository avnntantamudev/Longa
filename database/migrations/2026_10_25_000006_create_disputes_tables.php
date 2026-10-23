<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained();
            $table->foreignId('opened_by')->constrained('users');
            $table->string('reason', 40)->comment('lesson_not_happened | quality | no_show_contest | other');
            $table->text('description');
            $table->string('status', 20)->default('open')->comment('open | resolved');
            $table->string('resolution', 30)->nullable()->comment('released | refunded | partially_refunded');
            $table->unsignedInteger('trainer_share')->nullable()->comment('Parte do formador antes da comissão (Kz)');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('dispute_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('path')->comment('Disco privado');
            $table->string('original_name');
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_attachments');
        Schema::dropIfExists('disputes');
    }
};
