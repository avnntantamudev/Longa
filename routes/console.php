<?php

declare(strict_types=1);

use App\Events\PingBroadcast;
use App\Modules\Matching\Services\MatchingCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('longa:ping {message=pong}', function (string $message) {
    PingBroadcast::dispatch($message);
    $this->info("Evento enviado para o canal longa.ping: {$message}");
})->purpose('Envia um evento de teste pelo Reverb');

Artisan::command('longa:matching-tick', function (MatchingCoordinator $coordinator) {
    $coordinator->sweep();
})->purpose('TRIM TRIM: expira ofertas vencidas, envia ondas seguintes e expira pedidos');

// Rede de segurança do matching (o job AdvanceMatching de cada onda é o caminho normal)
Schedule::command('longa:matching-tick')->everyTenSeconds()->withoutOverlapping(1);

// / Respostas idempotentes guardadas expiram ao fim de 24 h
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete())->daily()->name('prune-idempotency-keys');

// Métricas do Horizon (gráficos de throughput e tempos de espera)
Schedule::command('horizon:snapshot')->everyFiveMinutes();
