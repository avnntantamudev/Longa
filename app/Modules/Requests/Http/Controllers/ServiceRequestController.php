<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Actions\CancelServiceRequest;
use App\Modules\Requests\Actions\CreateServiceRequest;
use App\Modules\Requests\Actions\RepublishServiceRequest;
use App\Modules\Requests\Http\Requests\RepublishServiceRequestRequest;
use App\Modules\Requests\Http\Requests\StoreServiceRequestRequest;
use App\Modules\Requests\Http\Resources\ClientRequestResource;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Pedidos de serviço do cliente.
 *
 * @tags Pedidos de serviço
 */
class ServiceRequestController extends Controller
{
    /**
     * Os meus pedidos.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = ServiceRequest::query()
            ->where('client_id', $request->user()?->getAuthIdentifier())
            ->with(['category'])
            ->latest('id')
            ->paginate(20);

        return ClientRequestResource::collection($requests);
    }

    /**
     * Publicar pedido.
     *
     * Passa logo a `searching` (5 min) e envia a 1.ª onda TRIM TRIM. Se não houver técnicos elegíveis,
     * o pedido volta já como `expired`.
     */
    public function store(StoreServiceRequestRequest $request, CreateServiceRequest $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $created = $action->handle($user, $request->requestData());

        return (new ClientRequestResource($created->load(['category'])))->response()->setStatusCode(201);
    }

    /**
     * Ver pedido.
     */
    public function show(ServiceRequest $serviceRequest): ClientRequestResource
    {
        Gate::authorize('view', $serviceRequest);

        return new ClientRequestResource($serviceRequest->load(['category']));
    }

    /**
     * Cancelar pedido (enquanto procura técnico).
     */
    public function cancel(Request $request, ServiceRequest $serviceRequest, CancelServiceRequest $action): ClientRequestResource
    {
        Gate::authorize('cancel', $serviceRequest);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        /** @var User $user */
        $user = $request->user();

        return new ClientRequestResource($action->handle($serviceRequest, $user, $data['reason'] ?? null)->load(['category']));
    }

    /**
     * Republicar pedido expirado ou cancelado.
     *
     * Cria um pedido novo, ligado ao original. Pode indicar nova data e novo valor.
     */
    public function republish(RepublishServiceRequestRequest $request, ServiceRequest $serviceRequest, RepublishServiceRequest $action): JsonResponse
    {
        Gate::authorize('republish', $serviceRequest);

        /** @var User $user */
        $user = $request->user();
        $amount = $request->validated('amount');

        $new = $action->handle($serviceRequest, $user, $request->validated('scheduled_at'), $amount === null ? null : (int) $amount);

        return (new ClientRequestResource($new->load(['category'])))->response()->setStatusCode(201);
    }
}
