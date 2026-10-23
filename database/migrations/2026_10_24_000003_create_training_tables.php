<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2: pedidos de aula, ofertas TRIM TRIM, aulas, conversas e eventos de score.
 * Montantes em Kz inteiros. Estados descritos em docs/state-machines.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('skill_id')->constrained();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('amount')->comment('Valor oferecido pelo aluno (Kz)');
            $table->unsignedInteger('commission')->comment('Comissão LONGA (Kz), descontada ao formador');
            $table->unsignedInteger('trainer_net')->comment('amount - commission (Kz)');
            $table->dateTime('scheduled_at');
            $table->string('modality', 20)->comment('in_person | online');
            $table->string('municipality', 80);
            $table->string('neighborhood', 80);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('state', 20)->default('draft')->comment('draft | searching | matched | expired | cancelled');
            $table->unsignedTinyInteger('wave')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_wave_at')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('assigned_trainer_profile_id')->nullable()->constrained('trainer_profiles');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('republished_from_id')->nullable()->constrained('training_requests');
            $table->timestamps();
            $table->index(['state', 'expires_at']);
            $table->index(['state', 'next_wave_at']);
            $table->index(['student_id', 'state']);
        });

        Schema::create('request_offers', function (Blueprint $table) {
            $table->id();
            // Sem cascade: o MySQL não o permite na base de uma coluna gerada (accepted_request_id); pedidos não se apagam
            $table->foreignId('training_request_id')->constrained();
            $table->foreignId('trainer_profile_id')->constrained();
            $table->unsignedTinyInteger('wave');
            $table->unsignedSmallInteger('rank')->comment('Posição na ordenação do matching');
            $table->unsignedInteger('distance_m');
            $table->unsignedTinyInteger('score_snapshot');
            $table->string('state', 20)->default('sent')->comment('sent | viewed | accepted | declined | expired | lost');
            $table->timestamp('sent_at');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['training_request_id', 'trainer_profile_id']);
            $table->index(['state', 'expires_at']);
            $table->index(['trainer_profile_id', 'state']);
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            // Único: um pedido origina no máximo uma aula (última barreira contra dupla aceitação)
            $table->foreignId('training_request_id')->unique()->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('trainer_profile_id')->constrained();
            $table->foreignId('skill_id')->constrained();
            $table->string('title', 120);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('commission');
            $table->unsignedInteger('trainer_net');
            $table->dateTime('scheduled_at');
            $table->string('modality', 20);
            $table->string('municipality', 80);
            $table->string('neighborhood', 80);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('state', 20)->default('scheduled');
            $table->string('escrow_reference', 64)->nullable();
            $table->timestamps();
            $table->index(['trainer_profile_id', 'state', 'scheduled_at']);
            $table->index(['student_id', 'scheduled_at']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable()->comment('Disco privado');
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('client_id', 64)->nullable()->comment('Id gerado no cliente para reenvios idempotentes');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'sender_id', 'client_id']);
        });

        Schema::create('score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->comment('offer_declined | offer_expired | late_cancellation | no_show ...');
            $table->smallInteger('points')->default(0)->comment('Calculado na Fase 6; por agora 0');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['trainer_profile_id', 'type', 'created_at']);
        });

        // Localização do pedido (POINT gerado + SPATIAL, ver identity migration)
        DB::statement('ALTER TABLE training_requests ADD COLUMN location POINT GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED SRID 4326 NOT NULL AFTER longitude');
        DB::statement('ALTER TABLE training_requests ADD SPATIAL INDEX training_requests_location_spatial (location)');

        // No máximo uma oferta aceite por pedido: a coluna só tem valor quando state = accepted
        DB::statement("ALTER TABLE request_offers ADD COLUMN accepted_request_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(state = 'accepted', training_request_id, NULL)) STORED");
        DB::statement('ALTER TABLE request_offers ADD UNIQUE INDEX request_offers_one_accepted_unique (accepted_request_id)');
    }

    public function down(): void
    {
        foreach (['score_events', 'messages', 'conversations', 'training_sessions', 'request_offers', 'training_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
