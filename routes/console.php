<?php

declare(strict_types=1);

use App\Events\PingBroadcast;
use App\Modules\Matching\Services\MatchingCoordinator;
use Illuminate\Support\Facades\Artisan;
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

// Métricas do Horizon (gráficos de throughput e tempos de espera)
Schedule::command('horizon:snapshot')->everyFiveMinutes();
