<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Autenticação só por OTP: não há palavra-passe nem tabela de reposição de palavra-passe
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->unique()->comment('E.164, ex.: +244923456789');
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('name')->nullable()->comment('Preenchido no onboarding');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status', 20)->default('active')->index()->comment('active | suspended');
            $table->string('suspension_reason')->nullable();
            $table->string('locale', 5)->default('pt');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
