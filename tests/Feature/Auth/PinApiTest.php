<?php

declare(strict_types=1);

use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Auth\Services\PinService;
use App\Modules\Notifications\Notifications\SecurityNotification;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/**
 * Entra com o código (OTP) pela API, como a app num aparelho novo. Devolve os dados da resposta.
 *
 * @return array<string, mixed>
 */
function otpLogin(string $phone = '+244923000111'): array
{
    test()->travel(5)->minutes(); // intervalo mínimo entre códigos para o mesmo número
    test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertAccepted();
    /** @var App\Modules\Notifications\Sms\ArraySmsProvider $sms */
    $sms = app(App\Modules\Notifications\Sms\SmsProvider::class);
    $messages = $sms->messagesTo($phone);
    preg_match('/\b(\d{6})\b/', (string) end($messages), $m);
    $code = $m[1] ?? throw new RuntimeException('Nenhum código enviado');

    return test()->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code, 'device_name' => 'Telemóvel da Ana'])->assertSuccessful()->json();
}

it('depois do código, cria o PIN e entra só com o PIN nesse aparelho', function () {
    $login = otpLogin();
    expect($login['pin_set'])->toBeFalse()->and(strlen($login['device_token']))->toBe(64);

    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertOk();

    app('auth')->forgetGuards();
    $this->withToken('')->postJson('/api/v1/auth/pin/login', ['device_token' => $login['device_token'], 'pin' => '482916'])
        ->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'user'])->assertJsonPath('user.pin_set', true);

    expect(User::query()->sole()->pin_hash)->not->toBe('482916')->not->toBeNull();
});

it('recusa PINs fáceis e confirmação diferente', function (string $pin, string $message) {
    $login = otpLogin();
    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => $pin, 'pin_confirmation' => $pin])
        ->assertStatus(422)->assertJsonPath('error.details.pin.0', $message);
})->with([
    ['111111', 'Não use o mesmo dígito repetido.'],
    ['123456', 'Não use números seguidos (ex.: 123456).'],
    ['654321', 'Não use números seguidos (ex.: 123456).'],
    ['121212', 'Esse PIN é fácil de adivinhar. Escolha outro.'],
    ['123123', 'Esse PIN é fácil de adivinhar. Escolha outro.'],
    ['000111', 'Não use os últimos dígitos do seu telefone.'],
]);

it('o PIN não funciona noutro aparelho nem com token inventado', function () {
    $login = otpLogin();
    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertOk();
    app('auth')->forgetGuards();

    $this->withToken('')->postJson('/api/v1/auth/pin/login', ['device_token' => str_repeat('a', 64), 'pin' => '482916'])
        ->assertStatus(401)->assertJsonPath('error.code', 'device_not_trusted');
});

it('5 PIN errados bloqueiam 15 min; 10 revogam o aparelho e avisam o dono', function () {
    $login = otpLogin();
    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertOk();
    app('auth')->forgetGuards();
    $try = fn (string $pin) => test()->withToken('')->postJson('/api/v1/auth/pin/login', ['device_token' => $login['device_token'], 'pin' => $pin]);

    foreach (range(1, 4) as $i) {
        $try('000000')->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid');
    }
    $try('000000')->assertStatus(422)->assertJsonPath('error.message', 'PIN errado. Bloqueado durante 15 minutos.');
    $try('482916')->assertStatus(429)->assertJsonPath('error.code', 'pin_locked'); // nem o certo entra durante o bloqueio

    $this->travel(16)->minutes();
    foreach (range(1, 4) as $i) {
        $try('000000')->assertStatus(422);
    }
    $try('000000')->assertStatus(401)->assertJsonPath('error.code', 'device_not_trusted');
    $try('482916')->assertStatus(401);

    expect(TrustedDevice::query()->sole()->revoked_reason)->toBe('too_many_attempts');
    Notification::assertSentTo(User::query()->sole(), SecurityNotification::class, fn ($n) => $n->kind === 'device_blocked');
});

it('trocar o PIN pede o atual e faz sair os outros aparelhos', function () {
    $first = otpLogin();
    $this->withToken($first['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertOk();
    app('auth')->forgetGuards();
    $second = otpLogin();

    $this->withToken($second['access_token'])->withHeader('X-Device-Token', $second['device_token'])
        ->postJson('/api/v1/auth/pin/change', ['current_pin' => '000000', 'pin' => '739104', 'pin_confirmation' => '739104'])->assertStatus(422);
    $this->withToken($second['access_token'])->withHeader('X-Device-Token', $second['device_token'])
        ->postJson('/api/v1/auth/pin/change', ['current_pin' => '482916', 'pin' => '739104', 'pin_confirmation' => '739104'])->assertOk();

    expect(TrustedDevice::query()->whereNull('revoked_at')->count())->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken('')->postJson('/api/v1/auth/pin/login', ['device_token' => $first['device_token'], 'pin' => '739104'])->assertStatus(401);
    Notification::assertSentTo(User::query()->sole(), SecurityNotification::class, fn ($n) => $n->kind === 'new_device');
});

it('repor um PIN esquecido exige ter entrado com o código há pouco', function () {
    $login = otpLogin();
    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertOk();

    // Logo a seguir ao código: pode repor
    $this->withToken($login['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '739104', 'pin_confirmation' => '739104'])->assertOk();

    // Sessão aberta só com o PIN (sem código recente): não pode
    app('auth')->forgetGuards();
    $pinLogin = $this->withToken('')->postJson('/api/v1/auth/pin/login', ['device_token' => $login['device_token'], 'pin' => '739104'])->json();
    $this->withToken($pinLogin['access_token'])->postJson('/api/v1/auth/pin/setup', ['pin' => '550193', 'pin_confirmation' => '550193'])
        ->assertStatus(409)->assertJsonPath('error.code', 'pin_exists');
});

it('pagar exige o PIN confirmado nos últimos 5 minutos', function () {
    $session = acceptedSession();
    $session->student->forceFill(['pin_hash' => bcrypt('482916')])->save();

    apiLogin($session->student, pinVerified: false);
    $pay = fn () => test()->postJson("/api/v1/sessions/{$session->id}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => 'pay-'.uniqid()]);

    $pay()->assertStatus(423)->assertJsonPath('error.code', 'pin_required');
    $this->postJson('/api/v1/auth/pin/verify', ['pin' => '000000'])->assertStatus(422);
    $this->postJson('/api/v1/auth/pin/verify', ['pin' => '482916'])->assertOk()->assertJsonPath('data.valid_for', PinService::RECENT_SECONDS);
    $pay()->assertOk();
});

it('lista e remove aparelhos de confiança', function () {
    $login = otpLogin();
    $this->withToken($login['access_token'])->withHeader('X-Device-Token', $login['device_token']);

    $devices = $this->getJson('/api/v1/auth/devices')->assertOk()->assertJsonPath('data.0.current', true)->json('data');
    $this->deleteJson("/api/v1/auth/devices/{$devices[0]['id']}")->assertNoContent();

    expect(TrustedDevice::query()->sole()->revoked_reason)->toBe('user');
});
