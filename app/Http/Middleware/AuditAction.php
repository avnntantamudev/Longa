<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regista a ação no audit log depois de o pedido ser processado.
 * Uso nas rotas: ->middleware('audit:auth.logout_all')
 *
 * Guarda apenas metadados (rota, estado HTTP); o corpo do pedido nunca é registado.
 */
class AuditAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        // Capturado antes (o logout limpa o utilizador) e depois (o login define-o)
        $userId = $request->user()?->getAuthIdentifier();

        $response = $next($request);

        $this->audit->record($action, null, [
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'success' => $response->isSuccessful(),
        ], $userId ?? $request->user()?->getAuthIdentifier());

        return $response;
    }
}
