<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->comment('ok | discrepancy');
            $table->json('issues')->comment('Lista de problemas encontrados (vazia se ok)');
            $table->json('totals')->comment('Saldos-chave no momento da verificação');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('checked_at')->useCurrent();
            $table->index(['status', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reconciliations');
    }
};
