<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Http\Requests\OpenDisputeRequest;
use App\Modules\Sessions\Http\Resources\DisputeResource;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\DisputeAttachment;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Disputas: abrir (cliente ou parte acusada de faltar), consultar e decidir (admin).
 *
 * @tags Disputas
 */
class DisputeController extends Controller
{
    /**
     * Abrir disputa (multipart, com anexos opcionais).
     *
     * Requer `Idempotency-Key`. O valor fica retido até à decisão do admin.
     * Erros: `dispute_not_allowed` (409).
     */
    public function store(OpenDisputeRequest $request, ServiceJob $session, OpenDispute $action): JsonResponse
    {
        Gate::authorize('view', $session);

        $dispute = $action->handle(
            $session,
            $this->user($request),
            $request->validated('reason'),
            $request->validated('description'),
            $request->attachments(),
        );

        return (new DisputeResource($dispute->load('attachments')))->response()->setStatusCode(201);
    }

    /**
     * Disputas de um serviço.
     */
    public function index(ServiceJob $session): JsonResponse
    {
        Gate::authorize('view', $session);

        return DisputeResource::collection($session->disputes()->with('attachments')->latest()->get())->response();
    }

    /**
     * Link temporário para uma evidência (participantes do serviço e admin).
     */
    public function attachmentLink(Request $request, DisputeAttachment $attachment): JsonResponse
    {
        $user = $this->user($request);
        /** @var Dispute $dispute */
        $dispute = Dispute::query()->with('session.provider')->findOrFail($attachment->dispute_id);
        abort_unless($user->isTeam() || $dispute->session->involves($user), 403);

        $minutes = (int) config('longa.disputes.attachment_link_minutes');
        $url = URL::temporarySignedRoute('disputes.attachment', now()->addMinutes($minutes), ['attachment' => $attachment->id], absolute: false);

        return response()->json(['data' => ['url' => $url, 'expires_in' => $minutes * 60]]);
    }

    /**
     * Download da evidência (rota assinada; o link expira).
     *
     * @unauthenticated
     */
    public function downloadAttachment(DisputeAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Decidir disputa (admin).
     *
     * Requer `Idempotency-Key`. `released` liberta tudo ao técnico, `refunded` devolve tudo ao cliente,
     * `partially_refunded` dá `provider_share` Kz ao técnico (antes da comissão) e devolve o resto.
     * Erros: `dispute_already_resolved` (409).
     */
    public function resolve(Request $request, Dispute $dispute, ResolveDispute $action): DisputeResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([ResolveDispute::RELEASE, ResolveDispute::REFUND, ResolveDispute::PARTIAL])],
            'provider_share' => ['nullable', 'required_if:decision,'.ResolveDispute::PARTIAL, 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $resolved = $action->handle(
            $dispute,
            $this->user($request),
            $data['decision'],
            isset($data['provider_share']) ? (int) $data['provider_share'] : null,
            $data['note'],
        );

        return new DisputeResource($resolved->load('attachments'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
