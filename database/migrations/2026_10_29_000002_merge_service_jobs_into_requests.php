<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2 (decisão 6): o pedido e o trabalho passam a ser um só registo com um só estado.
|  - as colunas de execução de service_jobs passam para service_requests;
|  - assigned_provider_profile_id → provider_profile_id; awaiting_since → completed_at;
|  - novas: payment_status (pending | held | failed; null em dinheiro), closed_reason (porque terminou sem pagamento
|    normal), on_the_way_at;
|  - as tabelas que apontavam para o trabalho (conversas, disputas, escrow, cobranças, linha do tempo, QR) passam
|    a apontar para o pedido, e as referências guardadas como classe (livro-razão, auditoria, score) também;
|  - service_jobs é apagada. O down() volta a criá-la com id = id do pedido.
*/
return new class extends Migration
{
    /** Tabelas com service_job_id → service_request_id */
    private const CHILDREN = ['conversations', 'disputes', 'escrow_holds', 'payment_charges', 'session_events', 'session_qr_tokens'];

    /** Referências guardadas como classe (morphs sem morph map) */
    private const MORPHS = [
        ['ledger_transactions', 'reference_type', 'reference_id'],
        ['audit_logs', 'subject_type', 'subject_id'],
        ['score_events', 'subject_type', 'subject_id'],
    ];

    private const JOB_CLASS = 'App\\Modules\\Sessions\\Models\\ServiceJob';

    private const REQUEST_CLASS = 'App\\Modules\\Requests\\Models\\ServiceRequest';

    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->renameColumn('assigned_provider_profile_id', 'provider_profile_id');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('commission_percent')->nullable()->after('provider_net')->comment('fixada quando o técnico aceita (VIP reduz)');
            $table->dateTime('ends_at')->nullable()->after('duration_minutes');
            $table->string('payment_status', 20)->nullable()->after('payment_method')->comment('pending | held | failed; null = dinheiro ou ainda sem técnico');
            $table->timestamp('payment_due_at')->nullable()->after('payment_status');
            $table->string('escrow_reference', 64)->nullable()->after('payment_due_at');
            $table->timestamp('on_the_way_at')->nullable()->after('matched_at');
            $table->timestamp('started_at')->nullable()->after('on_the_way_at');
            $table->timestamp('ended_at')->nullable()->after('started_at');
            $table->timestamp('completed_at')->nullable()->after('ended_at')->comment('concluído pelo técnico: começa a janela de confirmação');
            $table->timestamp('confirmed_at')->nullable()->after('completed_at');
            $table->timestamp('cash_confirmed_at')->nullable()->after('confirmed_at');
            $table->timestamp('no_show_contest_until')->nullable()->after('cash_confirmed_at');
            $table->string('closed_reason', 40)->nullable()->after('cancel_reason')
                ->comment('payment_failed | cancelled_by_client | cancelled_by_provider | no_show_provider | no_show_client | not_started | refunded');
            $table->index(['provider_profile_id', 'state', 'scheduled_at']);
            $table->index(['state', 'ends_at']);
            $table->index(['state', 'payment_due_at']);
        });

        $this->copyJobsIntoRequests();

        foreach (self::CHILDREN as $child) {
            $this->dropForeignOn($child, 'service_job_id');
            DB::statement("UPDATE {$child} c JOIN service_jobs j ON j.id = c.service_job_id SET c.service_job_id = j.service_request_id");
            Schema::table($child, fn (Blueprint $t) => $t->renameColumn('service_job_id', 'service_request_id'));
            Schema::table($child, fn (Blueprint $t) => $t->foreign('service_request_id')->references('id')->on('service_requests'));
        }

        foreach (self::MORPHS as [$table, $type, $id]) {
            DB::statement("UPDATE {$table} m JOIN service_jobs j ON j.id = m.{$id} SET m.{$id} = j.service_request_id, m.{$type} = ? WHERE m.{$type} = ?", [self::REQUEST_CLASS, self::JOB_CLASS]);
        }

        Schema::drop('service_jobs');
    }

    /**
     * As chaves estrangeiras ainda têm os nomes da altura da formação (training_session_id): procura-se o nome real.
     */
    private function dropForeignOn(string $table, string $column): void
    {
        $name = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')->value('CONSTRAINT_NAME');

        if ($name !== null) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign((string) $name));
        }
    }

    private function copyJobsIntoRequests(): void
    {
        DB::statement(<<<'SQL'
            UPDATE service_requests r JOIN service_jobs j ON j.service_request_id = r.id SET
                r.provider_profile_id = j.provider_profile_id,
                r.amount = j.amount, r.commission = j.commission, r.provider_net = j.provider_net,
                r.commission_percent = j.commission_percent,
                r.scheduled_at = j.scheduled_at, r.duration_minutes = j.duration_minutes, r.ends_at = j.ends_at,
                r.payment_due_at = j.payment_due_at, r.escrow_reference = j.escrow_reference,
                r.started_at = j.started_at, r.ended_at = j.ended_at, r.completed_at = j.awaiting_since,
                r.confirmed_at = j.confirmed_at, r.cash_confirmed_at = j.cash_confirmed_at,
                r.no_show_contest_until = j.no_show_contest_until,
                r.payment_status = CASE
                    WHEN j.payment_method = 'cash' THEN NULL
                    WHEN j.state = 'pending_payment' THEN 'pending'
                    WHEN j.state = 'payment_failed' THEN 'failed'
                    ELSE 'held' END,
                r.state = CASE j.state
                    WHEN 'pending_payment' THEN 'accepted'
                    WHEN 'scheduled' THEN 'accepted'
                    WHEN 'in_progress' THEN 'in_progress'
                    WHEN 'awaiting_confirmation' THEN 'completed'
                    WHEN 'completed' THEN 'paid'
                    WHEN 'partially_refunded' THEN 'paid'
                    WHEN 'disputed' THEN 'disputed'
                    ELSE 'cancelled' END,
                r.closed_reason = CASE j.state
                    WHEN 'payment_failed' THEN 'payment_failed'
                    WHEN 'cancelled_by_client' THEN 'cancelled_by_client'
                    WHEN 'cancelled_by_provider' THEN 'cancelled_by_provider'
                    WHEN 'no_show_provider' THEN 'no_show_provider'
                    WHEN 'no_show_client' THEN 'no_show_client'
                    WHEN 'refunded' THEN 'refunded'
                    ELSE NULL END
            SQL);
    }

    public function down(): void
    {
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->unique()->constrained('service_requests');
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('provider_profile_id')->constrained('provider_profiles');
            $table->foreignId('category_id')->constrained('categories');
            $table->string('title', 120);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('commission');
            $table->unsignedInteger('provider_net');
            $table->unsignedTinyInteger('commission_percent')->default(15);
            $table->string('payment_method', 20)->default('electronic');
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->dateTime('ends_at')->nullable();
            $table->string('modality', 20);
            $table->string('municipality', 80);
            $table->string('province', 40)->default('Luanda');
            $table->string('neighborhood', 80);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('state', 30)->default('scheduled');
            $table->string('escrow_reference', 64)->nullable();
            $table->timestamps();
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('awaiting_since')->nullable();
            $table->timestamp('no_show_contest_until')->nullable();
            $table->timestamp('cash_confirmed_at')->nullable();
            $table->index(['state', 'ends_at']);
            $table->index(['state', 'payment_due_at']);
            $table->index(['client_id', 'scheduled_at']);
            $table->index(['provider_profile_id', 'state', 'scheduled_at']);
        });

        // Um trabalho por pedido com técnico, com o mesmo id do pedido (as referências só mudam de nome)
        DB::statement(<<<'SQL'
            INSERT INTO service_jobs (id, service_request_id, client_id, provider_profile_id, category_id, title, amount, commission,
                provider_net, commission_percent, payment_method, scheduled_at, duration_minutes, ends_at, modality, municipality,
                province, neighborhood, latitude, longitude, state, escrow_reference, created_at, updated_at, payment_due_at,
                started_at, ended_at, confirmed_at, awaiting_since, no_show_contest_until, cash_confirmed_at)
            SELECT r.id, r.id, r.client_id, r.provider_profile_id, r.category_id, r.title, r.amount, r.commission, r.provider_net,
                COALESCE(r.commission_percent, 15), r.payment_method, r.scheduled_at, r.duration_minutes,
                COALESCE(r.ends_at, DATE_ADD(r.scheduled_at, INTERVAL r.duration_minutes MINUTE)), r.modality, r.municipality,
                r.province, r.neighborhood, r.latitude, r.longitude,
                CASE
                    WHEN r.closed_reason IS NOT NULL THEN r.closed_reason
                    WHEN r.state = 'accepted' AND r.payment_status = 'pending' THEN 'pending_payment'
                    WHEN r.state IN ('accepted', 'on_the_way') THEN 'scheduled'
                    WHEN r.state IN ('diagnosing', 'quote_sent', 'quote_accepted', 'in_progress') THEN 'in_progress'
                    WHEN r.state = 'completed' THEN 'awaiting_confirmation'
                    WHEN r.state = 'paid' AND EXISTS (SELECT 1 FROM disputes d WHERE d.service_request_id = r.id AND d.resolution = 'partially_refunded') THEN 'partially_refunded'
                    WHEN r.state = 'paid' THEN 'completed'
                    WHEN r.state = 'disputed' THEN 'disputed'
                    WHEN r.cancelled_by = r.client_id THEN 'cancelled_by_client'
                    ELSE 'cancelled_by_provider' END,
                r.escrow_reference, COALESCE(r.matched_at, r.created_at), r.updated_at, r.payment_due_at, r.started_at, r.ended_at,
                r.confirmed_at, r.completed_at, r.no_show_contest_until, r.cash_confirmed_at
            FROM service_requests r WHERE r.provider_profile_id IS NOT NULL
            SQL);
        DB::table('service_requests')->whereNotNull('provider_profile_id')->update(['state' => 'accepted']);

        // Só as referências a pedidos com trabalho voltam a apontar para o trabalho (os ids são iguais)
        foreach (self::MORPHS as [$table, $type, $id]) {
            DB::table($table)->where($type, self::REQUEST_CLASS)
                ->whereIn($id, DB::table('service_jobs')->select('id'))
                ->update([$type => self::JOB_CLASS]);
        }

        foreach (self::CHILDREN as $child) {
            $this->dropForeignOn($child, 'service_request_id');
            Schema::table($child, fn (Blueprint $t) => $t->renameColumn('service_request_id', 'service_job_id'));
            Schema::table($child, fn (Blueprint $t) => $t->foreign('service_job_id')->references('id')->on('service_jobs'));
        }

        // O índice composto passou a servir a chave estrangeira do técnico: tira-se a chave, depois o índice
        $this->dropForeignOn('service_requests', 'provider_profile_id');
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['provider_profile_id', 'state', 'scheduled_at']);
            $table->dropIndex(['state', 'ends_at']);
            $table->dropIndex(['state', 'payment_due_at']);
            $table->dropColumn([
                'commission_percent', 'ends_at', 'payment_status', 'payment_due_at', 'escrow_reference', 'on_the_way_at', 'started_at',
                'ended_at', 'completed_at', 'confirmed_at', 'cash_confirmed_at', 'no_show_contest_until', 'closed_reason',
            ]);
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->renameColumn('provider_profile_id', 'assigned_provider_profile_id');
        });
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreign('assigned_provider_profile_id')->references('id')->on('provider_profiles');
        });
    }
};
