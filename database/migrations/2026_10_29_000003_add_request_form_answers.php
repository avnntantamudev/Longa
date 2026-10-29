<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2.1: o pedido guarda as respostas do formulário da categoria (campos de texto, número e escolha em
| `answers`) e as fotos e vídeos enviados (service_request_media, disco privado, acesso por link temporário).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('description')->comment('respostas do formulário: {chave: valor}');
        });

        Schema::create('service_request_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->string('field_key', 60)->comment('campo do formulário (type photos | video)');
            $table->string('kind', 10)->comment('photo | video');
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['service_request_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_media');

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }
};
