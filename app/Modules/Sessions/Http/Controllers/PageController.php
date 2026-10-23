<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Models\TrainingSession;
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

    public function show(TrainingSession $session): Response
    {
        Gate::authorize('view', $session);

        return Inertia::render('Sessions/Show', ['sessionId' => $session->id]);
    }
}
