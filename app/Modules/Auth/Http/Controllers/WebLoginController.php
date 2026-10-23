<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Actions\AuthenticateWithOtp;
use App\Modules\Auth\Actions\RequestOtp;
use App\Modules\Auth\Http\Requests\RequestOtpRequest;
use App\Modules\Auth\Http\Requests\VerifyOtpRequest;
use App\Modules\Auth\Services\AuthSessionService;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login por OTP na PWA (sessão por cookie, Sanctum SPA).
 */
class WebLoginController extends Controller
{
    private const PHONE_SESSION_KEY = 'otp_phone';

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'phone' => $request->session()->get(self::PHONE_SESSION_KEY),
            'codeLength' => (int) config('longa.otp.length'),
            'resendIn' => (int) config('longa.otp.resend_cooldown'),
        ]);
    }

    public function sendCode(RequestOtpRequest $request, RequestOtp $action): RedirectResponse
    {
        $phone = $this->asFormError('phone', fn () => $action->handle($request->string('phone')->toString(), $request->ip()));

        $request->session()->put(self::PHONE_SESSION_KEY, $phone->e164);

        return back()->with('success', 'Enviámos um código para '.$phone->masked().'.');
    }

    public function verify(VerifyOtpRequest $request, AuthenticateWithOtp $action, AuthSessionService $sessions): RedirectResponse
    {
        ['user' => $user] = $this->asFormError('code', fn () => $action->handle(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
        ));

        $sessions->startWeb($request, $user);
        $request->session()->forget(self::PHONE_SESSION_KEY);

        return redirect()->intended($user->onboarded_at === null ? route('onboarding.show') : route('home'));
    }

    public function changePhone(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PHONE_SESSION_KEY);

        return redirect()->route('login');
    }

    public function logout(Request $request, AuthSessionService $sessions, AuditLogger $audit): RedirectResponse
    {
        $session = $sessions->current($request);
        if ($session !== null) {
            $sessions->revoke($session);
        }
        $audit->record('auth.logout', $session);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * Mostra erros de domínio (código errado, bloqueio, limites) junto do campo do formulário.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asFormError(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
