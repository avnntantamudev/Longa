<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Http\Resources\AddendumResource;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\AddendumFlow;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adendas ao orçamento aceite: o técnico propõe trabalho extra, o cliente aprova antes de ser feito e a equipa
 * revê as que passam o limite percentual do orçamento original.
 */
class AddendumController extends Controller
{
    /**
     * Adendas do pedido (cliente e técnico atribuído).
     */
    public function index(ServiceRequest $session): AnonymousResourceCollection
    {
        Gate::authorize('view', $session);

        return AddendumResource::collection($session->addenda()->latest('id')->get());
    }

    /**
     * Propor trabalho extra (técnico, durante a execução de um orçamento aceite).
     */
    public function store(Request $request, ServiceRequest $session, AddendumFlow $flow): JsonResponse
    {
        Gate::authorize('actAsProvider', $session);
        $data = $request->validate([
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            /** Valor do trabalho extra (Kz). @example 12000 */
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $addendum = $flow->submit($session, $this->user($request), (string) $data['description'], (int) $data['amount']);

        return (new AddendumResource($addendum))->response()->setStatusCode(201);
    }

    /**
     * Aprovar a adenda (cliente). Acima do limite vai para revisão da equipa.
     */
    public function approve(Request $request, QuoteAddendum $addendum, AddendumFlow $flow): AddendumResource
    {
        Gate::authorize('actAsClient', $addendum->request);

        return new AddendumResource($flow->clientApprove($addendum, $this->user($request)));
    }

    /**
     * Recusar a adenda (cliente): o trabalho extra não é feito.
     */
    public function reject(Request $request, QuoteAddendum $addendum, AddendumFlow $flow): AddendumResource
    {
        Gate::authorize('actAsClient', $addendum->request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return new AddendumResource($flow->clientReject($addendum, $this->user($request), $note));
    }

    /**
     * Pagar a adenda aprovada (cliente, pagamento digital): o valor junta-se ao escrow.
     */
    public function pay(Request $request, QuoteAddendum $addendum, AddendumFlow $flow): AddendumResource
    {
        Gate::authorize('pay', $addendum->request);
        $method = $request->validate(['method' => ['required', Rule::in(['multicaixa_express', 'reference', 'card'])]])['method'];

        return new AddendumResource($flow->pay($addendum, $this->user($request), (string) $method));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
