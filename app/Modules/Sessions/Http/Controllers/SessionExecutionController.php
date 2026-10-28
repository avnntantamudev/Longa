<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Actions\CancelSession;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\ConfirmWithoutQr;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\ReportNoShow;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Http\Requests\ConfirmSessionRequest;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Execução do serviço: iniciar, terminar e mostrar o QR, confirmar.
 *
 * @tags Execução do serviço
 */
class SessionExecutionController extends Controller
{
    /**
     * Iniciar serviço (técnico).
     */
    public function start(Request $request, ServiceJob $session, StartSession $action): SessionResource
    {
        Gate::authorize('actAsProvider', $session);

        return $this->resource($action->handle($session, $this->user($request)));
    }

    /**
     * Terminar serviço e obter o lote de QR (técnico).
     *
     * Devolve vários tokens, um por janela de 30 s: a app mostra-os em sequência, mesmo sem rede, e pede
     * um novo lote antes de acabarem (POST /sessions/{id}/qr). Os tokens só existem nesta resposta.
     */
    public function finish(Request $request, ServiceJob $session, FinishSession $action): JsonResponse
    {
        Gate::authorize('actAsProvider', $session);

        return $this->qrResponse($action->handle($session, $this->user($request)));
    }

    /**
     * Novo lote de QR (técnico), com o serviço à espera de confirmação.
     */
    public function qr(Request $request, ServiceJob $session, FinishSession $action): JsonResponse
    {
        Gate::authorize('actAsProvider', $session);

        return $this->qrResponse($action->handle($session, $this->user($request)));
    }

    /**
     * Confirmar serviço com o QR ou o código de 6 dígitos (cliente).
     *
     * Requer `Idempotency-Key`. Liberta o valor retido para o técnico (menos a comissão).
     * Erros: `qr_invalid` (422), `qr_locked` (429), `qr_outside_window` (409), `session_not_confirmable` (409).
     */
    public function confirm(ConfirmSessionRequest $request, ServiceJob $session, ConfirmSession $action): JsonResponse
    {
        Gate::authorize('actAsClient', $session);

        $confirmed = $action->handle(
            $session,
            $this->user($request),
            $request->validated('token'),
            $request->validated('code'),
            $request->filled('latitude') ? (float) $request->validated('latitude') : null,
            $request->filled('longitude') ? (float) $request->validated('longitude') : null,
        );

        return response()->json([
            'data' => $this->resource($confirmed)->resolve($request),
            'message' => $confirmed->isCash()
                ? 'Serviço confirmado. Confirme que pagou ao técnico em mão.'
                : 'Serviço confirmado, Kz '.number_format($confirmed->amount, 0, ',', '.').' libertados para o técnico.',
        ]);
    }

    /**
     * Confirmar sem QR (cliente), com o serviço à espera de confirmação.
     *
     * Requer `Idempotency-Key`. Liberta o valor retido para o técnico.
     */
    public function confirmWithoutQr(Request $request, ServiceJob $session, ConfirmWithoutQr $action): SessionResource
    {
        Gate::authorize('actAsClient', $session);

        return $this->resource($action->handle($session, $this->user($request)));
    }

    /**
     * Cancelar serviço agendado (cliente ou técnico).
     *
     * Requer `Idempotency-Key`. Reembolso conforme a política: cliente ≥ 6 h antes → total; depois → o técnico
     * fica com 25%; técnico cancela → total.
     */
    public function cancel(Request $request, ServiceJob $session, CancelSession $action): SessionResource
    {
        Gate::authorize('view', $session);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->resource($action->handle($session, $this->user($request), $data['reason'] ?? null));
    }

    /**
     * Reportar falta da outra parte (cliente ou técnico).
     *
     * Requer `Idempotency-Key`. A outra parte tem 24 h para contestar (abre disputa); sem contestação,
     * a liquidação segue a política.
     */
    public function noShow(Request $request, ServiceJob $session, ReportNoShow $action): SessionResource
    {
        Gate::authorize('view', $session);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->resource($action->handle($session, $this->user($request), $data['note'] ?? null));
    }

    /**
     * @param  array{session: ServiceJob, qr: array{batch_id: string, rotation_seconds: int, tokens: list<array{token: string, code: string, valid_from: string, expires_at: string}>}}  $result
     */
    private function qrResponse(array $result): JsonResponse
    {
        return response()->json([
            'data' => $this->resource($result['session'])->resolve(request()),
            'qr' => [...$result['qr'], 'server_time' => now()->toIso8601String()],
        ])->header('Cache-Control', 'no-store');
    }

    private function resource(ServiceJob $session): SessionResource
    {
        return new SessionResource($session->load(['provider.user', 'client', 'category', 'conversation']));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
