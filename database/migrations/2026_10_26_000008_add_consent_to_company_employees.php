<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| O colaborador decide se a empresa pode ver as suas formações na LONGA. Sem "accepted",
| o relatório RH não mostra nenhuma aula dele.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_employees', function (Blueprint $table) {
            $table->string('consent_status', 20)->default('pending')->after('status')->comment('pending | accepted | declined | revoked');
            $table->timestamp('consent_at')->nullable()->after('consent_status');
        });
    }

    public function down(): void
    {
        Schema::table('company_employees', function (Blueprint $table) {
            $table->dropColumn(['consent_status', 'consent_at']);
        });
    }
};
