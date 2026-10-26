<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\PinService;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operações com dinheiro (pagar uma aula, comprar VIP): exigem o PIN confirmado nos últimos 5 minutos
 * nesta sessão (POST /auth/pin/verify). Responde 423 `pin_required` para a app pedir o PIN e repetir.
 */
class RequireRecentPin
{
    public function __construct(private readonly AuthSessionService $sessions, private readonly PinService $pins) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->current($request);

        if ($session === null || ! $this->pins->isRecent($session->id)) {
            throw new ApiException('pin_required', 'Confirme com o seu PIN para continuar.', 423);
        }

        return $next($request);
    }
}
