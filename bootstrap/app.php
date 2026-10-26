<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuditAction;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Auth\Http\Middleware\EnsureAuthSessionIsActive;
use App\Modules\Auth\Http\Middleware\RequireRecentPin;
use App\Modules\Users\Http\Middleware\EnsureOnboarded;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorização dos canais privados em /api/v1/broadcasting/auth (cookie da PWA ou Bearer token)
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['api', 'auth:sanctum', 'auth.session'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // A PWA chama /api/v1 com o cookie de sessão (Sanctum SPA); a app Flutter usa Bearer tokens
        $middleware->statefulApi();

        $middleware->alias([
            'audit' => AuditAction::class,
            'auth.session' => EnsureAuthSessionIsActive::class,
            'role' => RoleMiddleware::class,
            'onboarded' => EnsureOnboarded::class,
            'idempotent' => EnsureIdempotency::class,
            'pin.recent' => RequireRecentPin::class,
        ]);

        $middleware->redirectGuestsTo('/entrar');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
