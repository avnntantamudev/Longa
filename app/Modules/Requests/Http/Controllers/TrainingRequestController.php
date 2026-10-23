<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Actions\CancelTrainingRequest;
use App\Modules\Requests\Actions\CreateTrainingRequest;
use App\Modules\Requests\Actions\RepublishTrainingRequest;
use App\Modules\Requests\Http\Requests\RepublishTrainingRequestRequest;
use App\Modules\Requests\Http\Requests\StoreTrainingRequestRequest;
use App\Modules\Requests\Http\Resources\StudentRequestResource;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Pedidos de aula do aluno.
 *
 * @tags Pedidos de aula
 */
class TrainingRequestController extends Controller
{
    /**
     * Os meus pedidos.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = TrainingRequest::query()
            ->where('student_id', $request->user()?->getAuthIdentifier())
            ->with(['skill', 'session'])
            ->latest('id')
            ->paginate(20);

        return StudentRequestResource::collection($requests);
    }

    /**
     * Publicar pedido.
     *
     * Passa logo a `searching` (5 min) e envia a 1.ª onda TRIM TRIM. Se não houver formadores elegíveis,
     * o pedido volta já como `expired`.
     */
    public function store(StoreTrainingRequestRequest $request, CreateTrainingRequest $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $created = $action->handle($user, $request->requestData());

        return (new StudentRequestResource($created->load(['skill', 'session'])))->response()->setStatusCode(201);
    }

    /**
     * Ver pedido.
     */
    public function show(TrainingRequest $trainingRequest): StudentRequestResource
    {
        Gate::authorize('view', $trainingRequest);

        return new StudentRequestResource($trainingRequest->load(['skill', 'session']));
    }

    /**
     * Cancelar pedido (enquanto procura formador).
     */
    public function cancel(Request $request, TrainingRequest $trainingRequest, CancelTrainingRequest $action): StudentRequestResource
    {
        Gate::authorize('cancel', $trainingRequest);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        /** @var User $user */
        $user = $request->user();

        return new StudentRequestResource($action->handle($trainingRequest, $user, $data['reason'] ?? null)->load(['skill', 'session']));
    }

    /**
     * Republicar pedido expirado ou cancelado.
     *
     * Cria um pedido novo, ligado ao original. Pode indicar nova data e novo valor.
     */
    public function republish(RepublishTrainingRequestRequest $request, TrainingRequest $trainingRequest, RepublishTrainingRequest $action): JsonResponse
    {
        Gate::authorize('republish', $trainingRequest);

        /** @var User $user */
        $user = $request->user();
        $amount = $request->validated('amount');

        $new = $action->handle($trainingRequest, $user, $request->validated('scheduled_at'), $amount === null ? null : (int) $amount);

        return (new StudentRequestResource($new->load(['skill', 'session'])))->response()->setStatusCode(201);
    }
}
