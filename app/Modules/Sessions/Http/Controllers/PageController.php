<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia dos serviços e do chat.
 */
class PageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sessions/Index');
    }

    public function show(ServiceRequest $session): Response
    {
        Gate::authorize('view', $session);

        return Inertia::render('Sessions/Show', ['sessionId' => $session->id]);
    }

    /** QR de confirmação (técnico do serviço). */
    public function qr(ServiceRequest $session): Response
    {
        Gate::authorize('actAsProvider', $session);

        return Inertia::render('Sessions/Qr', ['sessionId' => $session->id]);
    }

    /** Leitor do QR (cliente do serviço). */
    public function scan(ServiceRequest $session): Response
    {
        Gate::authorize('actAsClient', $session);

        return Inertia::render('Sessions/Scan', ['sessionId' => $session->id]);
    }

    /** Abrir disputa ou contestar falta (participantes). */
    public function dispute(ServiceRequest $session): Response
    {
        Gate::authorize('view', $session);

        return Inertia::render('Sessions/Dispute', [
            'sessionId' => $session->id,
            'contest' => in_array($session->closed_reason, ['no_show_provider', 'no_show_client'], true),
        ]);
    }
}
