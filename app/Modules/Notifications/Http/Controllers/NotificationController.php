<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\PushSubscriptionRequest;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Subscrições Web Push e notificações na app.
 *
 * @tags Notificações
 */
class NotificationController extends Controller
{
    /**
     * Registar subscrição Web Push deste dispositivo.
     *
     * Envie o objeto devolvido por `pushManager.subscribe()` (endpoint + keys). Repetir atualiza a subscrição.
     */
    public function subscribe(PushSubscriptionRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $subscription = $user->updatePushSubscription(
            (string) $request->validated('endpoint'),
            (string) $request->validated('keys.p256dh'),
            (string) $request->validated('keys.auth'),
            (string) ($request->validated('content_encoding') ?? 'aes128gcm'),
        );
        $subscription->forceFill(['device' => $request->validated('device')])->save();

        return response()->json(['data' => ['id' => $subscription->id]], 201);
    }

    /**
     * Remover subscrição Web Push (ex.: o utilizador desativou as notificações).
     */
    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $this->user($request)->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }

    /**
     * Notificações na app (mais recentes primeiro).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $page = $user->notifications()->latest()->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread' => $user->unreadNotifications()->count(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * Marcar notificação como lida.
     */
    public function read(Request $request, string $id): Response
    {
        $this->user($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    /**
     * Marcar todas como lidas.
     */
    public function readAll(Request $request): Response
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
