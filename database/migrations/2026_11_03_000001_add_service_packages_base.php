<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Pacotes de várias aulas com o mesmo técnico (ex.: formação ao domicílio durante uma semana).
| O pacote é um pedido "contrato" (passa pelo despacho e pelo pagamento); cada aula é um pedido filho
| (package_id + package_sequence) com o seu próprio ciclo: a caminho, início, QR e confirmação.
| categories.packages_enabled: null = herda da categoria-pai; no lançamento só Educação aceita pacotes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('packages_enabled')->nullable()->after('pricing_mode')->comment('aceita pacotes de várias aulas; null = herda do pai');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('republished_from_id')->comment('aula de um pacote: o pedido-contrato')->constrained('service_requests');
            $table->unsignedSmallInteger('package_sequence')->nullable()->after('package_id')->comment('nº da aula no pacote (1..N)');
            $table->unique(['package_id', 'package_sequence']);
        });

        DB::table('categories')->where('slug', 'educacao')->whereNull('parent_id')->update(['packages_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // A chave estrangeira usa o índice único: sai primeiro
            $table->dropForeign(['package_id']);
            $table->dropUnique(['package_id', 'package_sequence']);
            $table->dropColumn(['package_id', 'package_sequence']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('packages_enabled');
        });
    }
};
