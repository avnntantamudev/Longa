<?php

declare(strict_types=1);

namespace App\Modules\Requests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RequestsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publicar/republicar pedidos: por aluno
        RateLimiter::for('service-requests', fn (Request $request) => Limit::perHour((int) config('longa.requests.create_per_hour'))
            ->by('service-requests:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Aceitar/recusar ofertas: por formador
        RateLimiter::for('offer-responses', fn (Request $request) => Limit::perMinute((int) config('longa.matching.accept_per_minute'))
            ->by('offer-responses:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
