<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 1.3: base genérica para qualquer serviço.
|  - categories: árvore (parent_id), modo de preço, modalidades, documentos exigidos;
|  - category_fields: formulário dinâmico de cada categoria (herdado pelas subcategorias);
|  - category_prices: preço fixo, intervalo de referência, limites por hora e taxa de visita (Kz, inteiros);
|  - provider_zones: zonas de atuação do técnico (além da base e do raio do perfil);
|  - verification_documents: documentos exigidos por categoria, revistos pela equipa;
|  - provider_profiles: última localização e reputação (média e número de avaliações).
| category_provider_profile (técnico ↔ categorias) e platform_settings (parâmetros) já existem.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->restrictOnDelete();
            $table->string('area', 60)->nullable()->change();
            $table->text('description')->nullable()->after('slug');
            $table->string('icon', 40)->nullable()->after('description');
            $table->string('pricing_mode', 20)->default('quote')->after('icon')->comment('fixed | quote | hourly_package');
            $table->json('modalities')->nullable()->after('pricing_mode')->comment('["in_person","online"]');
            $table->json('required_documents')->nullable()->after('modalities')->comment('tipos de documento exigidos ao técnico');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('required_documents');
            $table->index(['parent_id', 'active', 'sort_order']);
        });

        Schema::create('category_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60)->comment('nome da resposta guardada no pedido');
            $table->string('label', 120);
            $table->string('type', 20)->comment('text | number | choice | photos | video');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable()->comment('escolhas (type = choice)');
            $table->string('help', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'key']);
        });

        Schema::create('category_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fixed_price')->nullable()->comment('Kz, modo fixed');
            $table->unsignedInteger('reference_min')->nullable()->comment('Kz, intervalo de referência (orçamentos)');
            $table->unsignedInteger('reference_max')->nullable();
            $table->unsignedInteger('hourly_min')->nullable()->comment('Kz/h, limites do modo hourly_package');
            $table->unsignedInteger('hourly_max')->nullable();
            $table->unsignedInteger('visit_fee')->nullable()->comment('Kz, taxa de visita de diagnóstico (modo quote)');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('provider_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80)->nullable();
            $table->string('province', 40);
            $table->string('municipality', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->nullable()->comment('nulo = município/província inteiros');
            $table->timestamps();
            $table->index(['province', 'municipality']);
        });

        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->comment('ex.: certificado_profissional, carta_conducao, registo_criminal');
            $table->string('file_path');
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['provider_profile_id', 'type']);
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->decimal('last_latitude', 10, 7)->nullable()->after('service_radius_m');
            $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
            $table->timestamp('last_location_at')->nullable()->after('last_longitude');
            $table->decimal('rating_average', 3, 2)->default(0)->after('score');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_average');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_latitude', 'last_longitude', 'last_location_at', 'rating_average', 'rating_count']);
        });
        Schema::dropIfExists('verification_documents');
        Schema::dropIfExists('provider_zones');
        Schema::dropIfExists('category_prices');
        Schema::dropIfExists('category_fields');
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id', 'active', 'sort_order']);
            $table->dropColumn(['parent_id', 'description', 'icon', 'pricing_mode', 'modalities', 'required_documents', 'sort_order']);
        });
        // area volta a ser obrigatória: preencher as que ficaram vazias (categorias novas da árvore)
        DB::table('categories')->whereNull('area')->update(['area' => 'Outros']);
        Schema::table('categories', function (Blueprint $table) {
            $table->string('area', 60)->nullable(false)->change();
        });
    }
};
