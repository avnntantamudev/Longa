<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Middleware;

use App\Modules\Users\Models\User;
use App\Modules\Users\Services\LegalDocuments;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quando há uma versão nova dos Termos ou da Política de Privacidade, quem já tem conta tem de a aceitar no
 * acesso seguinte: as páginas levam ao ecrã de aceitação; a API responde 403 legal_acceptance_required (a app
 * mostra o mesmo ecrã). O registo (onboarding) recolhe a primeira aceitação, por isso só conta quem o concluiu.
 */
class EnsureLegalAccepted
{
    /** Ler os textos, aceitar, sair e o PIN funcionam sempre */
    private const EXEMPT = [
        'legal.show', 'legal.accept', 'legal.accept.store', 'api.legal.show', 'api.me.legal', 'api.me.legal.accept', 'api.me',
        'logout', 'api.auth.logout', 'photos.show',
        'pin.setup', 'pin.setup.store', 'pin.unlock', 'pin.unlock.store', 'api.auth.pin.setup', 'api.auth.pin.verify',
    ];

    public function __construct(private readonly LegalDocuments $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Pedidos com token Bearer (app) só ficam autenticados no middleware da rota: resolver aqui também
        $user = $request->user() ?? ($request->bearerToken() !== null ? $request->user('sanctum') : null);
        if (! $user instanceof User || $user->onboarded_at === null || in_array($request->route()?->getName(), self::EXEMPT, true)) {
            return $next($request);
        }

        // Na sessão web guarda-se as versões já confirmadas, para não consultar a base de dados a cada pedido
        $current = implode('|', array_map(fn (string $d) => $this->legal->currentVersion($d), array_keys(LegalDocuments::DOCUMENTS)));
        $session = $request->hasSession() ? $request->session() : null;
        if ($session?->get('legal_ok') === $current) {
            return $next($request);
        }

        $pending = $this->legal->pendingFor($user);
        if ($pending === []) {
            $session?->put('legal_ok', $current);

            return $next($request);
        }

        $redirect = route('legal.accept');
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            throw new ApiException('legal_acceptance_required', 'Atualizámos os Termos de Utilização ou a Política de Privacidade. Aceite a nova versão para continuar.', 403, ['documents' => $pending, 'redirect' => $redirect]);
        }

        if ($request->isMethod('GET') && $session !== null) {
            $session->put('url.intended', $request->fullUrl());
        }

        return $request->header('X-Inertia') ? Inertia::location($redirect) : redirect($redirect);
    }
}
