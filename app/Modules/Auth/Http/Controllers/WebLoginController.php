<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Actions\AuthenticateWithOtp;
use App\Modules\Auth\Actions\RequestOtp;
use App\Modules\Auth\Http\Requests\RequestOtpRequest;
use App\Modules\Auth\Http\Requests\VerifyOtpRequest;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\PinService;
use App\Modules\Auth\Support\PhoneNumber;
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

    private const CHANNEL_SESSION_KEY = 'otp_channel';

    /** Veio de "Esqueci o PIN": depois do código cria um PIN novo */
    private const RESET_PIN_SESSION_KEY = 'otp_reset_pin';

    public function show(Request $request, PinService $pins): Response
    {
        // Aparelho de confiança com PIN criado: entra só com o PIN
        $device = $pins->findDevice($request->cookie(PinController::COOKIE));
        $forgotPin = $request->query('pin') === 'esqueci';
        if ($forgotPin) {
            $request->session()->put(self::RESET_PIN_SESSION_KEY, true);
        }
        if (! $forgotPin && $device !== null && $device->user->pin_hash !== null && ! $request->session()->has(self::PHONE_SESSION_KEY)) {
            return Inertia::render('Auth/Pin', [
                'mode' => 'login',
                'name' => $device->user->name,
                'phone' => PhoneNumber::parse($device->user->phone)?->masked() ?? $device->user->phone,
                'photo' => $device->user->photoUrl(128),
            ]);
        }

        return Inertia::render('Auth/Login', [
            'phone' => $request->session()->get(self::PHONE_SESSION_KEY),
            'channel' => $request->session()->get(self::CHANNEL_SESSION_KEY, 'sms'),
            'codeLength' => (int) config('longa.otp.length'),
            'resendIn' => (int) config('longa.otp.resend_cooldown'),
            'resetPin' => (bool) $request->session()->get(self::RESET_PIN_SESSION_KEY, false),
        ]);
    }

    public function sendCode(RequestOtpRequest $request, RequestOtp $action): RedirectResponse
    {
        $channel = $request->channel();
        $phone = $this->asFormError('phone', fn () => $action->handle($request->string('phone')->toString(), $request->ip(), $channel));

        $request->session()->put(self::PHONE_SESSION_KEY, $phone->e164);
        $request->session()->put(self::CHANNEL_SESSION_KEY, $channel->value);

        return back()->with('success', "Enviámos um código por {$channel->label()} para {$phone->masked()}.");
    }

    public function verify(VerifyOtpRequest $request, AuthenticateWithOtp $action, AuthSessionService $sessions, PinService $pins): RedirectResponse
    {
        ['user' => $user] = $this->asFormError('code', fn () => $action->handle(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
        ));

        $sessions->startWeb($request, $user);
        WebPinController::afterOtp($request, $user, $pins, $sessions);
        $resetPin = (bool) $request->session()->pull(self::RESET_PIN_SESSION_KEY, false);
        $request->session()->forget([self::PHONE_SESSION_KEY, self::CHANNEL_SESSION_KEY]);

        // Primeira vez (ou PIN esquecido): criar o PIN antes de continuar
        if ($user->pin_hash === null || $resetPin) {
            return redirect()->route('pin.setup');
        }

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
