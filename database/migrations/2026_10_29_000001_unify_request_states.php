<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| BISCA, Fase 2.2: estado único do pedido com histórico.
|  - service_requests.state passa aos estados do BISCA (draft → created, matched → accepted, expired → no_provider);
|  - service_requests.pricing_mode: modo de preço da categoria no momento do pedido (decide se há diagnóstico e orçamento);
|  - service_request_transitions: cada mudança de estado (de, para, quem, quando, motivo). Só acréscimos.
*/
return new class extends Migration
{
    /** Estados antigos → novos */
    private const STATES = ['draft' => 'created', 'matched' => 'accepted', 'expired' => 'no_provider'];

    /** Estados novos sem equivalente antigo → estado antigo mais próximo (só para reverter) */
    private const DOWN_EXTRA = [
        'on_the_way' => 'matched', 'diagnosing' => 'matched', 'quote_sent' => 'matched', 'quote_accepted' => 'matched',
        'in_progress' => 'matched', 'completed' => 'matched', 'paid' => 'matched', 'disputed' => 'matched',
    ];

    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('state', 20)->default('created')
                ->comment('created | searching | no_provider | accepted | on_the_way | diagnosing | quote_sent | quote_accepted | in_progress | completed | paid | disputed | cancelled')
                ->change();
            $table->string('pricing_mode', 20)->default('fixed')->after('category_id')->comment('fixed | quote | hourly_package (da categoria, no envio)');
        });

        foreach (self::STATES as $old => $new) {
            DB::table('service_requests')->where('state', $old)->update(['state' => $new]);
        }
        DB::table('service_requests')->update([
            'pricing_mode' => DB::raw('(SELECT c.pricing_mode FROM categories c WHERE c.id = service_requests.category_id)'),
        ]);

        Schema::create('service_request_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained();
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->foreignId('actor_id')->nullable()->comment('null = sistema (job, prazo)')->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['service_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_transitions');

        foreach ([...array_flip(self::STATES), ...self::DOWN_EXTRA] as $new => $old) {
            DB::table('service_requests')->where('state', $new)->update(['state' => $old]);
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
            $table->string('state', 20)->default('draft')->comment('draft | searching | matched | expired | cancelled')->change();
        });
    }
};
