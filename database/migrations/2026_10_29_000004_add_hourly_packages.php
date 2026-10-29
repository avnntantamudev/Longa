<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2.5 (hora/pacote, ex.: aulas):
|  - category_provider_profile.hourly_rate: preço por hora do técnico nessa categoria (dentro dos limites da categoria);
|  - category_packages: pacotes de aulas com desconto, configuráveis por categoria (herdados pelas subcategorias);
|  - service_requests: preço máximo por hora indicado pelo cliente, pacote escolhido, número de aulas e desconto;
|    o preço por hora do técnico que aceita fica gravado (hourly_rate) e fixa o total.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_provider_profile', function (Blueprint $table) {
            $table->unsignedInteger('hourly_rate')->nullable()->comment('Kz/h; obrigatório para receber pedidos por hora/pacote');
        });

        Schema::create('category_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sessions')->comment('número de aulas');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'sessions']);
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedInteger('max_hourly_rate')->nullable()->after('commission_percent')->comment('Kz/h, máximo aceite pelo cliente (hora/pacote)');
            $table->unsignedInteger('hourly_rate')->nullable()->after('max_hourly_rate')->comment('Kz/h do técnico que aceitou');
            $table->unsignedSmallInteger('sessions')->default(1)->after('hourly_rate');
            $table->foreignId('category_package_id')->nullable()->after('sessions')->constrained('category_packages')->nullOnDelete();
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('category_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_package_id');
            $table->dropColumn(['max_hourly_rate', 'hourly_rate', 'sessions', 'discount_percent']);
        });

        Schema::dropIfExists('category_packages');

        Schema::table('category_provider_profile', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
