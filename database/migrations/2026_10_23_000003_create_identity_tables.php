<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema de identidade (Fase 1). Ver docs/database.md.
 *
 * Localização: latitude/longitude são a fonte de verdade (escritas pelo Eloquent); a coluna
 * `location` é um POINT SRID 4326 gerado a partir delas, com índice SPATIAL.
 * Ordem dos eixos no MySQL: POINT(longitude, latitude). Sem coordenadas o ponto fica em (0,0),
 * longe de Angola, e as consultas filtram sempre latitude IS NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('category', 60)->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('municipality', 80)->nullable();
            $table->string('neighborhood', 80)->nullable();
            $table->string('education_level', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('trainer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->json('modalities')->nullable()->comment('["in_person","online"]');
            $table->unsignedInteger('rate_amount')->nullable()->comment('Kz inteiros');
            $table->string('rate_unit', 20)->nullable()->comment('hour | two_hours | day | session');
            $table->string('municipality', 80)->nullable();
            $table->string('neighborhood', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('service_radius_m')->default(5000);
            $table->string('verification_status', 20)->default('unverified')->comment('unverified | pending | verified | rejected');
            $table->unsignedTinyInteger('score')->default(50)->comment('0-100');
            $table->json('badges')->nullable();
            $table->boolean('is_available')->default(false);
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
            $table->index(['verification_status', 'is_available']);
        });

        Schema::create('skill_trainer_profile', function (Blueprint $table) {
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['trainer_profile_id', 'skill_id']);
            $table->index('skill_id');
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('nif', 20)->nullable()->unique();
            $table->string('sector', 80)->nullable();
            $table->string('municipality', 80)->nullable();
            $table->string('neighborhood', 80)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Gestores (RH) de cada empresa
        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('manager')->comment('owner | manager');
            $table->timestamps();
            $table->primary(['company_id', 'user_id']);
        });

        Schema::create('company_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('phone', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('department', 80)->nullable();
            $table->string('job_title', 80)->nullable();
            $table->string('status', 20)->default('active')->comment('active | inactive');
            $table->timestamps();
            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'department']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('identity_document | skill_certificate | in_person');
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected');
            $table->text('document_number')->nullable()->comment('Nº do BI, cifrado (cast encrypted)');
            $table->char('document_hash', 64)->nullable()->index()->comment('HMAC do nº do BI para detetar duplicados');
            $table->json('documents')->nullable()->comment('Caminhos no disco privado');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('terms | privacy | location | marketing');
            $table->string('version', 20);
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->index(['user_id', 'type']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->index();
            $table->string('code_hash');
            $table->string('purpose', 20)->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Sessões ativas (web e API), para listar dispositivos e terminar sessões
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->comment('web | api');
            $table->string('device_name', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->unsignedBigInteger('refresh_token_id')->nullable()->index();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        DB::statement('ALTER TABLE trainer_profiles ADD COLUMN location POINT GENERATED ALWAYS AS (ST_SRID(POINT(COALESCE(longitude, 0), COALESCE(latitude, 0)), 4326)) STORED SRID 4326 NOT NULL AFTER longitude');
        DB::statement('ALTER TABLE trainer_profiles ADD SPATIAL INDEX trainer_profiles_location_spatial (location)');
    }

    public function down(): void
    {
        foreach (['auth_sessions', 'otp_codes', 'consents', 'verifications', 'company_employees', 'company_user', 'companies', 'skill_trainer_profile', 'trainer_profiles', 'student_profiles', 'skills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
