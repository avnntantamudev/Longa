<?php

declare(strict_types=1);

use App\Events\PingBroadcast;
use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Wallet\Services\LedgerReconciler;
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

Artisan::command('longa:ledger:reconcile', function (LedgerReconciler $reconciler) {
    $report = $reconciler->run();
    foreach ($report->issues as $issue) {
        $this->line("[{$issue['severity']}] {$issue['message']}");
    }
    if (! $report->isOk()) {
        $this->error('Reconciliação com diferenças: administradores avisados.');

        return 1;
    }
    $this->info('Ledger reconciliado ('.$report->duration_ms.' ms).');

    return 0;
})->purpose('Verifica a integridade do ledger (somas, escrow, cache de saldos) e alerta se houver diferenças');

Artisan::command('longa:ledger:rebuild', function (LedgerReconciler $reconciler) {
    $this->info('Contas corrigidas: '.$reconciler->rebuildBalances());
})->purpose('Reconstrói a cache de saldos a partir dos lançamentos');

// Rede de segurança do matching (o job AdvanceMatching de cada onda é o caminho normal)
Schedule::command('longa:matching-tick')->everyTenSeconds()->withoutOverlapping(1);

// Regras automáticas das aulas (R1–R5)
Schedule::command('longa:sessions:tick')->everyMinute()->withoutOverlapping(5);

// Avisos de fim da subscrição VIP
Schedule::command('longa:vip:reminders')->dailyAt('09:00')->withoutOverlapping(30);

// Reconciliação do ledger
Schedule::command('longa:ledger:reconcile')->hourly()->withoutOverlapping(30);

// Respostas idempotentes guardadas expiram ao fim de 24 h
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete())->daily()->name('prune-idempotency-keys');

// Métricas do Horizon (gráficos de throughput e tempos de espera)
Schedule::command('horizon:snapshot')->everyFiveMinutes();
