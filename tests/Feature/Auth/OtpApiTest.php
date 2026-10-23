<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Auth\Models\AuthSession;
use App\Modules\Auth\Models\OtpCode;
use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;

const PHONE = '+244923456789';

function sms(): ArraySmsProvider
{
    /** @var ArraySmsProvider */
    return app(SmsProvider::class);
}

function lastCode(string $phone = PHONE): string
{
    $messages = sms()->messagesTo($phone);
    preg_match('/\b(\d{6})\b/', (string) end($messages), $m);

    return $m[1] ?? throw new RuntimeException('Nenhum código enviado');
}

function requestCode(string $phone = '923 456 789'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);
}

function verifyCode(string $code, string $phone = '923456789'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code, 'device_name' => 'Teste']);
}

beforeEach(fn () => Cache::flush());

it('envia um código e cria a conta ao validar', function () {
    requestCode()->assertStatus(202)->assertJsonStructure(['message', 'expires_in', 'resend_in']);

    $response = verifyCode(lastCode())->assertCreated();

    $response->assertJsonPath('created', true)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.phone', PHONE)
        ->assertJsonPath('user.needs_onboarding', true)
        ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'refresh_expires_in', 'session_id']);

    expect(User::query()->where('phone', PHONE)->exists())->toBeTrue()
        ->and(OtpCode::query()->first()?->code_hash)->not->toBe(lastCode());
});

it('faz login numa conta existente', function () {
    User::factory()->create(['phone' => PHONE]);
    requestCode();

    verifyCode(lastCode())->assertOk()->assertJsonPath('created', false);
});

it('responde da mesma forma a telefones com e sem conta', function () {
    User::factory()->create(['phone' => '+244911111111']);

    $withAccount = requestCode('911111111')->assertStatus(202)->json('message');
    $withoutAccount = requestCode('922222222')->assertStatus(202)->json('message');

    expect($withAccount)->toBe($withoutAccount);
});

it('valida o formato do telefone angolano', function () {
    requestCode('222 123 456')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejeita código errado com mensagem neutra', function () {
    requestCode();

    verifyCode(lastCode() === '000000' ? '111111' : '000000')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'otp_invalid')
        ->assertJsonPath('error.message', 'Código inválido ou expirado.');
});

it('rejeita código expirado', function () {
    requestCode();
    $code = lastCode();

    $this->travel(301)->seconds();

    verifyCode($code)->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
});

it('só aceita cada código uma vez', function () {
    requestCode();
    $code = lastCode();

    verifyCode($code)->assertCreated();
    verifyCode($code)->assertStatus(422);
});

it('bloqueia o telefone depois de demasiadas tentativas erradas', function () {
    requestCode();
    $code = lastCode();
    $wrong = $code === '999999' ? '888888' : '999999';

    foreach (range(1, 4) as $i) {
        verifyCode($wrong)->assertStatus(422);
    }
    verifyCode($wrong)->assertStatus(429)->assertJsonPath('error.code', 'otp_locked');

    // Mesmo o código certo deixa de funcionar durante o bloqueio
    verifyCode($code)->assertStatus(429);
    requestCode()->assertStatus(429)->assertJsonPath('error.code', 'otp_locked');

    expect(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(6);
});

it('impõe intervalo entre envios e limite por hora por telefone', function () {
    requestCode()->assertStatus(202);
    requestCode()->assertStatus(429)->assertJsonPath('error.code', 'otp_resend_too_soon');

    foreach (range(2, 5) as $i) {
        $this->travel(61)->seconds();
        requestCode()->assertStatus(202);
    }

    $this->travel(61)->seconds();
    requestCode()->assertStatus(429)->assertJsonPath('error.code', 'otp_too_many_requests');
});

it('limita pedidos de código por IP', function () {
    config(['longa.otp.max_sends_per_ip_per_hour' => 3]);

    foreach (['911000001', '911000002', '911000003'] as $phone) {
        requestCode($phone)->assertStatus(202);
    }

    requestCode('911000004')->assertStatus(429);
});

it('impede contas suspensas de entrar', function () {
    User::factory()->suspended()->create(['phone' => PHONE]);
    requestCode();

    verifyCode(lastCode())->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
});

describe('com sessão iniciada', function () {
    beforeEach(function () {
        requestCode();
        $this->tokens = verifyCode(lastCode())->json();
    });

    it('acede a /me com o token de acesso', function () {
        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', PHONE);
    });

    it('não aceita o token de refresh como token de acesso', function () {
        $this->withToken($this->tokens['refresh_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('expira o token de acesso', function () {
        $this->travel(61)->minutes();

        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('roda os tokens no refresh e invalida o par anterior', function () {
        $new = $this->postJson('/api/v1/auth/token/refresh', ['refresh_token' => $this->tokens['refresh_token']])
            ->assertOk()
            ->json();

        expect($new['access_token'])->not->toBe($this->tokens['access_token'])
            ->and($new['session_id'])->toBe($this->tokens['session_id']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/token/refresh', ['refresh_token' => $this->tokens['refresh_token']])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($new['access_token'])->getJson('/api/v1/me')->assertOk();
    });

    it('lista as sessões e termina outra sessão', function () {
        $other = AuthSession::query()->create(['user_id' => User::query()->value('id'), 'type' => 'api', 'device_name' => 'Outro']);

        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['device_name' => 'Teste', 'current' => true]);

        $this->withToken($this->tokens['access_token'])->deleteJson("/api/v1/auth/sessions/{$other->id}")->assertNoContent();

        expect($other->fresh()?->revoked_at)->not->toBeNull();
    });

    it('não deixa terminar sessões de outros utilizadores', function () {
        $foreign = AuthSession::query()->create(['user_id' => User::factory()->create()->id, 'type' => 'api']);

        $this->withToken($this->tokens['access_token'])->deleteJson("/api/v1/auth/sessions/{$foreign->id}")->assertNotFound();
    });

    it('termina a sessão atual no logout', function () {
        $this->withToken($this->tokens['access_token'])->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('termina todas as sessões', function () {
        $this->travel(61)->seconds();
        requestCode();
        $second = verifyCode(lastCode())->json();

        $this->withToken($this->tokens['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk()->assertJsonPath('revoked', 2);

        $this->app['auth']->forgetGuards();
        $this->withToken($second['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        expect(AuditLog::query()->where('action', 'auth.logout_all')->exists())->toBeTrue();
    });

    it('corta o acesso quando a conta é suspensa', function () {
        User::query()->update(['status' => 'suspended']);

        $this->withToken($this->tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    });
});
