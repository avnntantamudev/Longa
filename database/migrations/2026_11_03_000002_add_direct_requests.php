<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| "Pedir ao mesmo técnico" (só técnicos VIP): o pedido vai só para esse técnico, que tem X minutos para aceitar.
| Se recusar ou não responder, o cliente pode enviar o pedido a todos (nova tentativa normal).
| provider_rebook_interests: clientes que quiseram voltar a chamar um técnico que não é VIP (um registo por
| cliente e técnico), para o técnico saber quantos clientes perde por não ser VIP.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('direct_provider_id')->nullable()->after('package_sequence')
                ->comment('pedido direto a este técnico (VIP); null = despacho normal')->constrained('provider_profiles');
        });

        Schema::create('provider_rebook_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_request_id')->nullable()->comment('serviço de onde o cliente pediu')->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider_profile_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_rebook_interests');

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direct_provider_id');
        });
    }
};
