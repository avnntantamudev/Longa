<?php

declare(strict_types=1);

use App\Modules\Auth\Models\AuthSession;
use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function webCode(string $phone): string
{
    /** @var ArraySmsProvider $sms */
    $sms = app(SmsProvider::class);
    $messages = $sms->messagesTo($phone);
    preg_match('/\b(\d{6})\b/', (string) end($messages), $m);

    return $m[1];
}

beforeEach(fn () => Cache::flush());

it('faz login na PWA por OTP e leva um utilizador novo para o registo', function () {
    $this->get('/entrar')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('phone', null));

    $this->post('/entrar/codigo', ['phone' => '923 456 789'])->assertRedirect()->assertSessionHas('otp_phone', '+244923456789');

    $this->get('/entrar')->assertInertia(fn (Assert $page) => $page->where('phone', '+244923456789'));

    // Primeira vez: cria o PIN e depois segue para o registo
    $this->post('/entrar/verificar', ['phone' => '+244923456789', 'code' => webCode('+244923456789')])
        ->assertRedirect(route('pin.setup'))
        ->assertCookie('longa_device');
    $this->post('/pin/criar', ['pin' => '482916', 'pin_confirmation' => '482916'])->assertRedirect(route('onboarding.show'));

    $this->assertAuthenticated();
    expect(AuthSession::query()->where('type', 'web')->count())->toBe(1);

    // A sessão da PWA também autentica a API (Sanctum SPA)
    // (o browser envia sempre Referer/Origin, que o Sanctum usa para reconhecer pedidos da PWA)
    $this->getJson('/api/v1/me', ['Referer' => 'http://localhost/onboarding'])->assertOk()->assertJsonPath('data.needs_onboarding', true);
});

it('mostra o erro do código junto do campo', function () {
    $this->post('/entrar/codigo', ['phone' => '923456789']);

    $this->from('/entrar')->post('/entrar/verificar', ['phone' => '+244923456789', 'code' => '000000'])
        ->assertRedirect('/entrar')
        ->assertSessionHasErrors(['code' => 'Código inválido ou expirado.']);
});

it('leva utilizadores já registados para o início', function () {
    User::factory()->create(['phone' => '+244923456789', 'pin_hash' => bcrypt('482916')]);
    $this->post('/entrar/codigo', ['phone' => '923456789']);

    $this->post('/entrar/verificar', ['phone' => '+244923456789', 'code' => webCode('+244923456789')])->assertRedirect(route('home'));
});

it('protege as páginas autenticadas e exige o registo concluído', function () {
    $this->get('/perfil')->assertRedirect('/entrar');

    $user = User::factory()->notOnboarded()->create();
    loginWeb($user);

    $this->get('/perfil')->assertRedirect(route('onboarding.show'));
    $this->get('/onboarding')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Onboarding/Start'));
});

it('mostra o onboarding do técnico com áreas e zonas', function () {
    $profile = ProviderProfile::factory()->create();
    loginWeb($profile->user);

    $this->get('/onboarding/tecnico')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Onboarding/Provider')
        ->has('categories')
        ->has('zones'));
});

it('termina a sessão web', function () {
    loginWeb(User::factory()->create());

    $this->post('/sair')->assertRedirect(route('home'));
    $this->assertGuest();
    expect(AuthSession::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('só o admin vê a página de verificações', function () {
    loginWeb(User::factory()->create());
    $this->get('/admin/verificacoes')->assertForbidden();
});
