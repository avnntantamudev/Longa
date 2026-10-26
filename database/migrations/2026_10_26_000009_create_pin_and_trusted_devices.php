<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| PIN de 6 dígitos (cifrado, como uma palavra-passe) e aparelhos de confiança.
| O PIN só funciona num aparelho que já entrou com o código SMS/WhatsApp: esse aparelho guarda um token
| secreto (aqui só o hash SHA-256). As tentativas erradas contam por aparelho.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('phone_verified_at');
            $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('name', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable()->comment('logout | pin_changed | too_many_attempts | user | other_account');
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_set_at']);
        });
    }
};
