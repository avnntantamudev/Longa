<?php

declare(strict_types=1);

namespace App\Modules\Sessions;

use App\Modules\Sessions\Services\SessionTimeoutRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

class SessionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
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
