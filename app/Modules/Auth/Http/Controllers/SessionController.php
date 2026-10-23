<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RefreshTokenRequest;
use App\Modules\Auth\Http\Resources\AuthSessionResource;
use App\Modules\Auth\Models\AuthSession;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * @tags Autenticação
 */
class SessionController extends Controller
{
    public function __construct(private readonly AuthSessionService $sessions, private readonly AuditLogger $audit) {}

    /**
     * Renovar tokens.
     *
     * Troca o token de refresh por um novo par; o par anterior deixa de funcionar.
     *
     * @unauthenticated
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->sessions->refresh($request->string('refresh_token')->toString());

        return response()->json($tokens->toArray());
    }

    /**
     * Listar sessões ativas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->sessions->current($request)?->id;

        $sessions = AuthSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->latest('last_active_at')
            ->get();

        return AuthSessionResource::collection($sessions->map(fn (AuthSession $s) => new AuthSessionResource($s, $currentId)));
    }

    /**
     * Terminar uma sessão (outro dispositivo).
     */
    public function destroy(Request $request, AuthSession $session): Response
    {
        abort_unless($session->user_id === $request->user()?->getAuthIdentifier(), 404);

        $this->sessions->revoke($session);
        $this->audit->record('auth.session_revoked', $session);

        return response()->noContent();
    }

    /**
     * Terminar sessão neste dispositivo.
     */
    public function logout(Request $request): Response
    {
        $session = $this->sessions->current($request);

        if ($session !== null) {
            $this->sessions->revoke($session);
        }

        $this->endWebSession($request);
        $this->audit->record('auth.logout', $session);

        return response()->noContent();
    }

    /**
     * Terminar sessão em todos os dispositivos.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $this->sessions->revokeAll($user);
        $this->endWebSession($request);
        $this->audit->record('auth.logout_all', null, ['sessions' => $count], $user->id);

        return response()->json(['revoked' => $count]);
    }

    private function endWebSession(Request $request): void
    {
        if ($request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
