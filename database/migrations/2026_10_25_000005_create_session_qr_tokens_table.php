<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de confirmação da aula (QR rotativo + código de 6 dígitos). Só se guardam hashes (HMAC-SHA256).
 * Cada token vale numa janela curta (30 s + margem); um lote cobre várias janelas seguidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained();
            $table->foreignId('trainer_profile_id')->constrained();
            $table->uuid('batch_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->char('code_hash', 64)->index();
            $table->timestamp('valid_from');
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('active')->comment('active | used | revoked');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Tentativas falhadas na janela deste token');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['training_session_id', 'status', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_qr_tokens');
    }
};
