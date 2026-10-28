<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| BISCA: o domínio deixa de ser formação e passa a serviços (decisão 2026-10-28, docs/BISCA-PROGRESS.md).
| formador → técnico (provider), aluno → cliente (client), área → categoria, pedido de formação → pedido de serviço,
| aula → trabalho (service_job; junta-se ao pedido na Fase 2). O gateway de pagamento deixa de se chamar
| "provider" para não se confundir com o técnico. Só renomeia: nenhum dado é apagado e o down() repõe tudo.
*/
return new class extends Migration
{
    /** @var array<string, string> tabela antiga => nova */
    private const TABLES = [
        'trainer_profiles' => 'provider_profiles',
        'student_profiles' => 'client_profiles',
        'skills' => 'categories',
        'skill_trainer_profile' => 'category_provider_profile',
        'skill_suggestions' => 'category_suggestions',
        'training_requests' => 'service_requests',
        'training_sessions' => 'service_jobs',
        'trainer_subscriptions' => 'provider_subscriptions',
    ];

    /** @var array<string, array<string, string>> tabela (nome novo) => [coluna antiga => nova] */
    private const COLUMNS = [
        'categories' => ['category' => 'area'],
        'category_provider_profile' => ['trainer_profile_id' => 'provider_profile_id', 'skill_id' => 'category_id'],
        'category_suggestions' => ['trainer_profile_id' => 'provider_profile_id', 'skill_id' => 'category_id'],
        'service_requests' => ['student_id' => 'client_id', 'skill_id' => 'category_id', 'trainer_net' => 'provider_net', 'assigned_trainer_profile_id' => 'assigned_provider_profile_id'],
        'service_jobs' => ['training_request_id' => 'service_request_id', 'student_id' => 'client_id', 'trainer_profile_id' => 'provider_profile_id', 'skill_id' => 'category_id', 'trainer_net' => 'provider_net'],
        'provider_subscriptions' => ['trainer_profile_id' => 'provider_profile_id', 'provider' => 'gateway'],
        'request_offers' => ['training_request_id' => 'service_request_id', 'trainer_profile_id' => 'provider_profile_id'],
        'conversations' => ['training_session_id' => 'service_job_id'],
        'disputes' => ['training_session_id' => 'service_job_id', 'trainer_share' => 'provider_share'],
        'escrow_holds' => ['training_session_id' => 'service_job_id', 'student_id' => 'client_id', 'trainer_profile_id' => 'provider_profile_id', 'trainer_net' => 'provider_net'],
        'payment_charges' => ['training_session_id' => 'service_job_id', 'student_id' => 'client_id', 'provider' => 'gateway', 'provider_payload' => 'gateway_payload'],
        'score_events' => ['trainer_profile_id' => 'provider_profile_id'],
        'session_events' => ['training_session_id' => 'service_job_id'],
        'session_qr_tokens' => ['training_session_id' => 'service_job_id', 'trainer_profile_id' => 'provider_profile_id'],
    ];

    /** @var array<string, string> valores guardados como texto (papéis, contas do livro-razão, classes em morphs) */
    private const VALUES = [
        'student' => 'client',
        'trainer' => 'provider',
        'TrainerProfile' => 'ProviderProfile',
        'StudentProfile' => 'ClientProfile',
        'TrainingSession' => 'ServiceJob',
        'TrainingRequest' => 'ServiceRequest',
        'TrainerSubscription' => 'ProviderSubscription',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $old => $new) {
            Schema::rename($old, $new);
        }
        $this->dropOneAcceptedGuard();
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $old => $new) {
                    $t->renameColumn($old, $new);
                }
            });
        }
        $this->addOneAcceptedGuard('service_request_id');
        $this->renameValues(self::VALUES);
    }

    public function down(): void
    {
        $this->renameValues(array_flip(self::VALUES));
        $this->dropOneAcceptedGuard();
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $old => $new) {
                    $t->renameColumn($new, $old);
                }
            });
        }
        $this->addOneAcceptedGuard('training_request_id');
        foreach (array_reverse(self::TABLES, true) as $old => $new) {
            Schema::rename($new, $old);
        }
    }

    /** A coluna gerada "no máximo uma oferta aceite por pedido" depende da coluna do pedido: sai e volta. */
    private function dropOneAcceptedGuard(): void
    {
        DB::statement('ALTER TABLE request_offers DROP INDEX request_offers_one_accepted_unique');
        DB::statement('ALTER TABLE request_offers DROP COLUMN accepted_request_id');
    }

    private function addOneAcceptedGuard(string $requestColumn): void
    {
        DB::statement("ALTER TABLE request_offers ADD COLUMN accepted_request_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(state = 'accepted', {$requestColumn}, NULL)) STORED");
        DB::statement('ALTER TABLE request_offers ADD UNIQUE INDEX request_offers_one_accepted_unique (accepted_request_id)');
    }

    /** @param array<string, string> $map */
    private function renameValues(array $map): void
    {
        foreach ($map as $from => $to) {
            DB::table('roles')->where('name', $from)->update(['name' => $to]);
        }
        foreach ([['ledger_accounts', 'code'], ['ledger_accounts', 'type'], ['ledger_accounts', 'owner_type'], ['ledger_transactions', 'reference_type'], ['audit_logs', 'subject_type']] as [$table, $column]) {
            foreach ($map as $from => $to) {
                // Só prefixos de conta ("trainer_available:12") e nomes de classe; nunca palavras soltas
                $needle = ctype_lower($from) ? "{$from}_" : "\\{$from}";
                $replacement = ctype_lower($from) ? "{$to}_" : "\\{$to}";
                DB::table($table)->where($column, 'like', '%'.str_replace(['\\', '_'], ['\\\\', '\\_'], $needle).'%')
                    ->update([$column => DB::raw('REPLACE('.DB::getQueryGrammar()->wrap($column).', '.DB::getPdo()->quote($needle).', '.DB::getPdo()->quote($replacement).')')]);
            }
        }
    }
};
