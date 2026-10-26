<?php

declare(strict_types=1);

namespace App\Modules\Sessions;

use App\Modules\Sessions\Services\SessionTimeoutRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SessionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Leituras de QR: por utilizador e por IP (além do bloqueio por aula após tentativas erradas)
        RateLimiter::for('qr-confirm', fn (Request $request) => [
            Limit::perMinute(10)->by('qr-confirm:user:'.($request->user()?->getAuthIdentifier() ?? 'guest')),
            Limit::perMinute(30)->by('qr-confirm:ip:'.$request->ip()),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\DemoLessonCommand::class]);

            Artisan::command('longa:sessions:tick', function (SessionTimeoutRules $rules) {
                foreach ($rules->run() as $rule => $count) {
                    if ($count > 0) {
                        $this->info("{$rule}: {$count}");
                    }
                }
            })->purpose('Aplica as regras automáticas das aulas (pagamento, confirmação, faltas)');
        }
    }
}
