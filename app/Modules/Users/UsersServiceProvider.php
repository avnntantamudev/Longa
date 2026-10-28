<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Modules\Users\Console\TeamMemberCommand;
use Illuminate\Support\ServiceProvider;

class UsersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TeamMemberCommand::class]);
        }
    }
}
