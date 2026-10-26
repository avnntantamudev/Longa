<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Middleware\EnsurePinUnlocked;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\PinService;
use App\Modules\Auth\Support\PhoneNumber;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PIN na PWA: entrar só com o PIN neste aparelho, criar/repor o PIN e desbloquear após inatividade.
 */
class WebPinController extends Controller
{
    /** O cookie do aparelho dura 400 dias (máximo aceite pelos browsers) */
    private const COOKIE_MINUTES = 400 * 24 * 60;

    public function __construct(private readonly PinService $pins, private readonly AuthSessionService $sessions) {}

    /**
     * Depois do código (OTP): marca o aparelho como de confiança e devolve o cookie.
     */
    public static function afterOtp(Request $request, User $user, PinService $pins, AuthSessionService $sessions): void
    {
        $device = $pins->trust($user, $request);
        Cookie::queue(Cookie::make(PinController::COOKIE, $device['token'], self::COOKIE_MINUTES, '/', null, config('session.secure'), true, false, 'lax'));

        $session = $sessions->current($request);
        if ($session !== null) {
            Cache::put("otp-fresh:{$session->id}", $device['device']->id, 600);
            $pins->markRecent($session->id);
        }
        self::markUnlocked($request);
    }

    /**
     * Entrar com o PIN (aparelho de confiança, sem código).
     */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6']], ['pin.digits' => 'O PIN tem 6 dígitos.']);
        $device = $this->pins->findDevice($request->cookie(PinController::COOKIE));

        if ($device === null) {
            Cookie::queue(Cookie::forget(PinController::COOKIE));

            return redirect()->route('login')->with('error', 'Por segurança, entre com o código SMS.');
        }

        try {
            $user = $this->pins->attempt($device, $data['pin']);
        } catch (ApiException $e) {
            if ($e->errorCode === 'device_not_trusted') {
                Cookie::queue(Cookie::forget(PinController::COOKIE));

                return redirect()->route('login')->with('error', $e->getMessage());
            }

            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }

        $session = $this->sessions->startWeb($request, $user);
        $this->pins->markRecent($session->id);
        self::markUnlocked($request);

        return redirect()->intended(route('home'));
    }

    /**
     * Usar outra conta neste aparelho: o aparelho deixa de entrar só com o PIN.
     */
    public function otherAccount(Request $request): RedirectResponse
    {
        $device = $this->pins->findDevice($request->cookie(PinController::COOKIE));
        if ($device !== null) {
            $this->pins->revoke($device, 'other_account');
        }
        Cookie::queue(Cookie::forget(PinController::COOKIE));

        return redirect()->route('login');
    }

    public function setupPage(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Auth/PinSetup', ['reset' => $user->pin_hash !== null]);
    }

    /**
     * Criar o PIN (ou repor, logo a seguir ao código).
     */
    public function setup(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6', 'confirmed']], ['pin.confirmed' => 'Os dois PIN não coincidem.', 'pin.digits' => 'O PIN tem 6 dígitos.']);
        /** @var User $user */
        $user = $request->user();
        $session = $this->sessions->current($request);
        $fresh = $session === null ? null : Cache::get("otp-fresh:{$session->id}");

        if ($user->pin_hash !== null && $fresh === null) {
            return redirect()->route('home')->with('error', 'Já tem PIN. Para o trocar, use "Alterar PIN" no perfil.');
        }

        try {
            $this->pins->set($user, $data['pin'], $this->pins->findDevice($request->cookie(PinController::COOKIE)));
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['pin' => $e->details['pin'][0] ?? $e->getMessage()]);
        }
        self::markUnlocked($request);

        return redirect()->intended($user->onboarded_at === null ? route('onboarding.show') : route('home'))->with('success', 'PIN criado. Da próxima vez entra só com o PIN.');
    }

    public function unlockPage(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Auth/Pin', [
            'mode' => 'unlock',
            'name' => $user->name,
            'phone' => PhoneNumber::parse($user->phone)?->masked() ?? $user->phone,
            'photo' => $user->photoUrl(128),
        ]);
    }

    /**
     * Desbloquear depois de inatividade.
     */
    public function unlock(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6']], ['pin.digits' => 'O PIN tem 6 dígitos.']);
        /** @var User $user */
        $user = $request->user();
        $device = $this->pins->findDevice($request->cookie(PinController::COOKIE));

        try {
            if ($device !== null && $device->user_id === $user->id) {
                $this->pins->attempt($device, $data['pin']);
            } else {
                $this->pins->checkWithoutDevice($user, $data['pin']);
            }
        } catch (ApiException $e) {
            if ($e->errorCode === 'device_not_trusted') {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                Cookie::queue(Cookie::forget(PinController::COOKIE));

                return redirect()->route('login')->with('error', $e->getMessage());
            }

            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }

        $session = $this->sessions->current($request);
        if ($session !== null) {
            $this->pins->markRecent($session->id);
        }
        self::markUnlocked($request);

        return redirect()->intended(route('home'));
    }

    private static function markUnlocked(Request $request): void
    {
        $request->session()->put(EnsurePinUnlocked::UNLOCKED_AT, now()->getTimestamp());
        $request->session()->put(EnsurePinUnlocked::LAST_SEEN, now()->getTimestamp());
    }
}
