<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Api\IdempotencyHeaderDocumentation;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Atrás de um túnel HTTPS ou balanceador: confiar nos cabeçalhos X-Forwarded-* desse proxy
        $proxies = (string) config('app.trusted_proxies');
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
        }

        // OpenAPI: documentar o cabeçalho Idempotency-Key nas rotas que o exigem
        Scramble::configure()->withOperationTransformers(IdempotencyHeaderDocumentation::class);

        // `php artisan dev` sem Redis (fila em database): o Horizon não processa nada, por isso corre um worker
        // nas mesmas filas. Sem isto o código OTP fica parado na fila "notifications".
        if ($this->app->runningInConsole() && config('queue.default') !== 'redis') {
            DevCommands::except('horizon', 'queue');
            DevCommands::artisan('queue:listen --queue=payments,notifications,default --tries=3 --timeout=0', 'queues');
        }

        // `php artisan dev` também arranca o tempo real (sem ele o alerta do TRIM TRIM e o chat ao vivo não chegam)
        // e as tarefas agendadas (rondas do despacho, confirmação automática, orçamentos expirados, lembretes)
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('reverb:start --port='.config('reverb.servers.reverb.port'), 'reverb');
            DevCommands::artisan('schedule:work', 'schedule');
        }
    }
}
