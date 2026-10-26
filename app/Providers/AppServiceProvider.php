<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Api\IdempotencyHeaderDocumentation;
use Dedoc\Scramble\Scramble;
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
        // OpenAPI: documentar o cabeçalho Idempotency-Key nas rotas que o exigem
        Scramble::configure()->withOperationTransformers(IdempotencyHeaderDocumentation::class);
    }
}
