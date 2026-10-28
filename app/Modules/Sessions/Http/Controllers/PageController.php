<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia das aulas e do chat.
 */
class PageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sessions/Index');
    }

    public function show(ServiceJob $session): Response
    {
        Gate::authorize('view', $session);

        return Inertia::render('Sessions/Show', ['sessionId' => $session->id]);
    }

    /** QR de confirmação (formador da aula). */
    public function qr(ServiceJob $session): Response
    {
        Gate::authorize('actAsProvider', $session);

        return Inertia::render('Sessions/Qr', ['sessionId' => $session->id]);
    }

    /** Leitor do QR (aluno da aula). */
    public function scan(ServiceJob $session): Response
    {
        Gate::authorize('actAsClient', $session);

        return Inertia::render('Sessions/Scan', ['sessionId' => $session->id]);
    }

    /** Abrir disputa ou contestar falta (participantes). */
    public function dispute(ServiceJob $session): Response
    {
        Gate::authorize('view', $session);

        return Inertia::render('Sessions/Dispute', [
            'sessionId' => $session->id,
            'contest' => in_array($session->state, [SessionState::NoShowProvider, SessionState::NoShowClient], true),
        ]);
    }
}
