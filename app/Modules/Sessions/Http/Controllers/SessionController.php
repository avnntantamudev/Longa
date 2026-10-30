<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Actions\SendMessage;
use App\Modules\Sessions\Events\MessagesRead;
use App\Modules\Sessions\Http\Requests\SendMessageRequest;
use App\Modules\Sessions\Http\Resources\MessageResource;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Sessions\Models\Message;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serviços e chat do serviço.
 *
 * @tags Serviços e chat
 */
class SessionController extends Controller
{
    /**
     * Os meus serviços (como cliente ou técnico).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = ServiceRequest::query()
            ->whereNotNull('provider_profile_id')
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('provider_profile_id', $user->providerProfile->id ?? 0))
            ->with(['provider.user', 'client', 'category', 'conversation'])
            ->orderBy('scheduled_at')
            ->paginate(20);

        return SessionResource::collection($sessions);
    }

    /**
     * Ver serviço.
     */
    public function show(ServiceRequest $session): SessionResource
    {
        Gate::authorize('view', $session);

        return new SessionResource($session->load(['provider.user', 'client', 'category', 'conversation']));
    }

    /**
     * Mensagens do chat.
     *
     * Paginação por cursor: `before` (mensagens mais antigas) ou `after` (novas desde a última recebida).
     */
    public function messages(Request $request, ServiceRequest $session): AnonymousResourceCollection
    {
        Gate::authorize('chat', $session);

        $query = Message::query()
            ->whereHas('conversation', fn ($q) => $q->where('service_request_id', $session->id))
            ->with('sender');

        if ($request->filled('after')) {
            $messages = $query->where('id', '>', $request->integer('after'))->orderBy('id')->limit(100)->get();
        } else {
            $messages = $query
                ->when($request->filled('before'), fn ($q) => $q->where('id', '<', $request->integer('before')))
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->reverse()
                ->values();
        }

        return MessageResource::collection($messages);
    }

    /**
     * Enviar mensagem (texto e/ou PDF).
     */
    public function send(SendMessageRequest $request, ServiceRequest $session, SendMessage $action): JsonResponse
    {
        Gate::authorize('chat', $session);

        /** @var User $user */
        $user = $request->user();

        $message = $action->handle($session, $user, $request->validated('body'), $request->attachment(), $request->validated('client_id'));

        return (new MessageResource($message->load('sender')))->response()->setStatusCode(201);
    }

    /**
     * Marcar como lidas as mensagens da outra parte (estado de leitura). A outra parte é avisada em tempo real.
     */
    public function markRead(Request $request, ServiceRequest $session): JsonResponse
    {
        Gate::authorize('chat', $session);

        /** @var User $user */
        $user = $request->user();
        $unread = Message::query()
            ->whereHas('conversation', fn ($q) => $q->where('service_request_id', $session->id))
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at');
        $lastId = (int) (clone $unread)->max('id');
        $count = $unread->update(['read_at' => now()]);

        if ($count > 0) {
            MessagesRead::dispatch($session->id, $user->id, $lastId);
        }

        return response()->json(['data' => ['marked' => $count]]);
    }

    /**
     * Linha do tempo do serviço (participantes).
     */
    public function events(ServiceRequest $session): JsonResponse
    {
        Gate::authorize('view', $session);

        $events = $session->events()->orderBy('id')->get()->map(fn (SessionEvent $e) => [
            'type' => $e->type,
            'from_state' => $e->from_state,
            'to_state' => $e->to_state,
            'by' => match ($e->actor_id) {
                null => 'system',
                $session->client_id => 'client',
                $session->provider->user_id => 'provider',
                default => 'admin',
            },
            'created_at' => $e->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $events]);
    }

    /**
     * Link temporário para descarregar o PDF de uma mensagem.
     *
     * Devolve um URL assinado válido por alguns minutos (config longa.chat.attachment_link_minutes).
     */
    public function attachmentLink(Message $message): JsonResponse
    {
        $session = $message->conversation->session;
        Gate::authorize('chat', $session);
        abort_unless($message->hasAttachment(), 404);

        $minutes = (int) config('longa.chat.attachment_link_minutes');
        $url = URL::temporarySignedRoute('chat.attachment', now()->addMinutes($minutes), ['message' => $message->id], absolute: false);

        return response()->json(['data' => ['url' => $url, 'expires_in' => $minutes * 60]]);
    }

    /**
     * Download do PDF (rota assinada, sem sessão: o link expira).
     *
     * @unauthenticated
     */
    public function downloadAttachment(Message $message): StreamedResponse
    {
        abort_unless($message->hasAttachment() && Storage::disk('local')->exists((string) $message->attachment_path), 404);

        return Storage::disk('local')->download((string) $message->attachment_path, $message->attachment_name ?? 'anexo', [
            'Content-Type' => $message->attachment_mime ?? 'application/pdf',
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
