<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 3.4: chat com fotos (além de PDF), estado de leitura e sinalização de mensagens suspeitas
| (contactos ou tentativas de combinar fora da plataforma) para revisão da equipa. A outra parte vê sempre o texto
| mascarado; o texto original só fica guardado nas mensagens sinalizadas, para a equipa.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_mime', 100)->nullable()->after('attachment_size');
            $table->timestamp('read_at')->nullable()->after('client_id')->comment('lida pela outra parte');
            $table->boolean('flagged')->default(false)->after('read_at');
            $table->json('flag_reasons')->nullable()->after('flagged')->comment('phone | email | link | off_platform');
            $table->text('flagged_body')->nullable()->after('flag_reasons')->comment('texto original (só para a equipa)');
            $table->string('flag_status', 20)->nullable()->after('flagged_body')->comment('pending | reviewed');
            $table->foreignId('reviewed_by')->nullable()->after('flag_status')->constrained('users');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
            $table->index(['flagged', 'flag_status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['flagged', 'flag_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['attachment_mime', 'read_at', 'flagged', 'flag_reasons', 'flagged_body', 'flag_status', 'reviewed_at', 'review_note']);
        });
    }
};
