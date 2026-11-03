<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\LessonReschedules;
use App\Modules\Requests\Services\PackageCancellation;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Pacotes de várias aulas: desistir do pacote, procurar outro técnico e remarcar aulas.
 *
 * @tags Pacotes
 */
class PackageController extends Controller
{
    /**
     * Desistir do pacote (cliente ou técnico).
     *
     * As aulas que ainda não começaram são canceladas. Técnico: todas devolvidas ao cliente (conta como
     * cancelamento grave). Cliente: devolvidas, menos a próxima se faltar pouco tempo (conta como dada).
     */
    public function cancel(Request $request, ServiceRequest $session, PackageCancellation $packages): SessionResource
    {
        Gate::authorize('view', $session);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->resource($packages->cancel($session, $this->user($request), $data['reason'] ?? null));
    }

    /**
     * Procurar outro técnico para as aulas que faltam (depois de o técnico desistir).
     *
     * Cria um novo pedido com as datas que ainda dão tempo, os mesmos dados e o mesmo preço máximo.
     */
    public function replace(Request $request, ServiceRequest $session, PackageCancellation $packages): JsonResponse
    {
        Gate::authorize('view', $session);
        $created = $packages->replace($session, $this->user($request));

        return response()->json(['data' => ['request_id' => $created->id]], 201);
    }

    /**
     * Propor outra hora para uma aula do pacote.
     */
    public function proposeReschedule(Request $request, ServiceRequest $session, LessonReschedules $reschedules): SessionResource
    {
        Gate::authorize('view', $session);
        /** Nova data e hora (ISO 8601). @example 2026-11-12T10:00:00+01:00 */
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);

        return $this->resource($reschedules->propose($session, $this->user($request), Carbon::parse($data['scheduled_at'])));
    }

    /**
     * Aceitar a nova hora proposta pela outra parte.
     */
    public function acceptReschedule(Request $request, ServiceRequest $session, LessonReschedules $reschedules): SessionResource
    {
        Gate::authorize('view', $session);

        return $this->resource($reschedules->accept($session, $this->user($request)));
    }

    /**
     * Recusar a nova hora proposta pela outra parte (a aula mantém a hora).
     */
    public function rejectReschedule(Request $request, ServiceRequest $session, LessonReschedules $reschedules): SessionResource
    {
        Gate::authorize('view', $session);

        return $this->resource($reschedules->reject($session, $this->user($request)));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function resource(ServiceRequest $session): SessionResource
    {
        return new SessionResource($session->fresh(['provider.user', 'client', 'category', 'conversation']) ?? $session);
    }
}
