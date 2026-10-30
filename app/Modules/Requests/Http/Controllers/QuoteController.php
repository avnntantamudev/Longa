<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Http\Resources\QuoteResource;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Serviços por orçamento: diagnóstico e orçamento do técnico, resposta do cliente.
 */
class QuoteController extends Controller
{
    /**
     * Começar o diagnóstico (técnico chegou ao local). A taxa de visita, se houver, é paga em mão.
     */
    public function diagnosis(Request $request, ServiceRequest $session, QuoteFlow $flow): SessionResource
    {
        Gate::authorize('actAsProvider', $session);

        return $this->session($flow->startDiagnosis($session, $this->user($request)));
    }

    /**
     * Orçamentos do pedido (cliente e técnico atribuído).
     */
    public function index(ServiceRequest $session): AnonymousResourceCollection
    {
        Gate::authorize('view', $session);

        return QuoteResource::collection($session->quotes()->latest('id')->get());
    }

    /**
     * Enviar orçamento (técnico): mão de obra, materiais, prazo de execução. Validade definida no painel.
     */
    public function store(Request $request, ServiceRequest $session, QuoteFlow $flow): JsonResponse
    {
        Gate::authorize('actAsProvider', $session);

        $data = $request->validate([
            /** Mão de obra (Kz). @example 15000 */
            'labour_amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            /** Total dos materiais (Kz). @example 8000 */
            'materials_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            /** Lista de materiais (opcional). */
            'materials' => ['nullable', 'array', 'max:30'],
            'materials.*.descricao' => ['required', 'string', 'max:120'],
            'materials.*.quantidade' => ['required', 'integer', 'min:1', 'max:10000'],
            'materials.*.valor' => ['required', 'integer', 'min:0', 'max:100000000'],
            /** Prazo de execução (dias). @example 1 */
            'execution_days' => ['required', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
            /** Pagamento por fases (orçamentos grandes; por omissão 30/40/30). */
            'phased' => ['sometimes', 'boolean'],
            /** Percentagens das fases (opcional), ex.: [50, 50]. */
            'phases' => ['nullable', 'array', 'min:2', 'max:5'],
            'phases.*' => ['integer', 'min:10', 'max:90'],
        ]);

        if (($data['labour_amount'] + ($data['materials_amount'] ?? 0)) <= 0) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Os dados enviados são inválidos.', 'details' => ['labour_amount' => ['O orçamento tem de ter valor.']]]], 422);
        }

        /** @var array{labour_amount: int, materials_amount?: int, materials?: list<array{descricao: string, quantidade: int, valor: int}>|null, execution_days: int, notes?: string|null, phased?: bool, phases?: list<int>|null} $data */
        $quote = $flow->submit($session, $this->user($request), $data);

        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }

    /**
     * Aceitar orçamento (cliente): o preço fica bloqueado. Paga o total menos a taxa de visita.
     */
    public function accept(Request $request, Quote $quote, QuoteFlow $flow): SessionResource
    {
        Gate::authorize('actAsClient', $quote->request);

        return $this->session($flow->accept($quote, $this->user($request)));
    }

    /**
     * Pedir revisão do orçamento (cliente): volta ao diagnóstico.
     */
    public function revision(Request $request, Quote $quote, QuoteFlow $flow): SessionResource
    {
        Gate::authorize('actAsClient', $quote->request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->session($flow->requestRevision($quote, $this->user($request), $note));
    }

    /**
     * Recusar orçamento (cliente): o pedido é cancelado; o técnico fica com a taxa de visita.
     */
    public function refuse(Request $request, Quote $quote, QuoteFlow $flow): SessionResource
    {
        Gate::authorize('actAsClient', $quote->request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->session($flow->refuse($quote, $this->user($request), $note));
    }

    private function session(ServiceRequest $request): SessionResource
    {
        return new SessionResource($request->fresh(['provider.user', 'client', 'category', 'conversation']) ?? $request);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
