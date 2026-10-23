<?php

declare(strict_types=1);

use App\Events\PingBroadcast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('longa:ping {message=pong}', function (string $message) {
    PingBroadcast::dispatch($message);
    $this->info("Evento enviado para o canal longa.ping: {$message}");
})->purpose('Envia um evento de teste pelo Reverb');

// Métricas do Horizon (gráficos de throughput e tempos de espera)
Schedule::command('horizon:snapshot')->everyFiveMinutes();
