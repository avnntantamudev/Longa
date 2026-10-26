<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Actions\SendMessage;
use App\Modules\Sessions\Http\Requests\SendMessageRequest;
use App\Modules\Sessions\Http\Resources\MessageResource;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Sessions\Models\Message;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Aulas e chat da aula.
 *
 * @tags Aulas e chat
 */
class SessionController extends Controller
{
    /**
     * As minhas aulas (como aluno ou formador).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = TrainingSession::query()
            ->where(fn ($q) => $q->where('student_id', $user->id)->orWhere('trainer_profile_id', $user->trainerProfile->id ?? 0))
            ->with(['trainer.user', 'student', 'skill', 'conversation'])
            ->orderBy('scheduled_at')
            ->paginate(20);

        return SessionResource::collection($sessions);
    }

    /**
     * Ver aula.
     */
    public function show(TrainingSession $session): SessionResource
    {
        Gate::authorize('view', $session);

        return new SessionResource($session->load(['trainer.user', 'student', 'skill', 'conversation']));
    }

    /**
     * Mensagens do chat.
     *
     * Paginação por cursor: `before` (mensagens mais antigas) ou `after` (novas desde a última recebida).
     */
    public function messages(Request $request, TrainingSession $session): AnonymousResourceCollection
    {
        Gate::authorize('chat', $session);

        $query = Message::query()
            ->whereHas('conversation', fn ($q) => $q->where('training_session_id', $session->id))
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
    public function send(SendMessageRequest $request, TrainingSession $session, SendMessage $action): JsonResponse
    {
        Gate::authorize('chat', $session);

        /** @var User $user */
        $user = $request->user();

        $message = $action->handle($session, $user, $request->validated('body'), $request->attachment(), $request->validated('client_id'));

        return (new MessageResource($message->load('sender')))->response()->setStatusCode(201);
    }

    /**
     * Linha do tempo da aula (participantes).
     */
    public function events(TrainingSession $session): JsonResponse
    {
        Gate::authorize('view', $session);

        $events = $session->events()->orderBy('id')->get()->map(fn (SessionEvent $e) => [
            'type' => $e->type,
            'from_state' => $e->from_state,
            'to_state' => $e->to_state,
            'by' => match ($e->actor_id) {
                null => 'system',
                $session->student_id => 'student',
                $session->trainer->user_id => 'trainer',
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

        return Storage::disk('local')->download((string) $message->attachment_path, $message->attachment_name ?? 'documento.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
