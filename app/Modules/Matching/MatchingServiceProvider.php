<?php

declare(strict_types=1);

namespace App\Modules\Matching;

use App\Modules\Matching\Console\SimulateProvidersCommand;
use Illuminate\Support\ServiceProvider;

class MatchingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SimulateProvidersCommand::class]);
        }
    }
}
