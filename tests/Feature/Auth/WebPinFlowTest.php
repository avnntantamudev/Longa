<?php

declare(strict_types=1);

use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Auth\Services\PinService;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
});

/**
 * Utilizador com PIN e aparelho de confiança; devolve o token do cookie.
 */
function trustedUser(array $attributes = []): array
{
    $user = User::factory()->create(['pin_hash' => Hash::make('482916'), ...$attributes]);
    $token = app(PinService::class)->trust($user, request())['token'];

    return [$user, $token];
}

it('num aparelho de confiança, /entrar mostra o PIN em vez do número e entra só com o PIN', function () {
    [$user, $token] = trustedUser(['name' => 'Ana Silva']);

    $this->withCookie('longa_device', $token)->get('/entrar')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Pin')->where('mode', 'login')->where('name', 'Ana Silva'));

    $this->withCookie('longa_device', $token)->from('/entrar')->post('/entrar/pin', ['pin' => '000000'])
        ->assertSessionHasErrors(['pin' => 'PIN errado.']);
    $this->assertGuest();

    $this->withCookie('longa_device', $token)->post('/entrar/pin', ['pin' => '482916'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
    $this->get('/perfil')->assertOk();
});

it('sem cookie de aparelho, /entrar pede o número (e "Esqueci o PIN" também)', function () {
    [, $token] = trustedUser();

    $this->get('/entrar')->assertInertia(fn (Assert $p) => $p->component('Auth/Login'));
    $this->withCookie('longa_device', $token)->get('/entrar?pin=esqueci')
        ->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('resetPin', true));
});

it('depois de 1 hora sem uso pede o PIN; a API da PWA responde 423 com o destino', function () {
    [$user] = trustedUser();
    loginWeb($user);

    $this->get('/perfil')->assertOk();
    $this->travel(61)->minutes();
    $this->get('/perfil')->assertRedirect(route('pin.unlock'));
    $this->getJson('/api/v1/sessions', ['Referer' => 'http://localhost/servicos'])
        ->assertStatus(423)->assertJsonPath('error.code', 'pin_unlock_required')->assertJsonPath('error.details.redirect', route('pin.unlock'));

    $this->get('/desbloquear')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Pin')->where('mode', 'unlock'));
    $this->post('/desbloquear', ['pin' => '482916'])->assertRedirect();
    $this->get('/perfil')->assertOk();
});

it('o uso contínuo mantém a sessão desbloqueada', function () {
    [$user] = trustedUser();
    loginWeb($user);

    foreach (range(1, 3) as $i) {
        $this->travel(50)->minutes();
        $this->get('/perfil')->assertOk();
    }
});

it('o administrador volta a confirmar o PIN ao fim de 8 horas mesmo com uso', function () {
    [$admin] = trustedUser();
    $admin->assignRole(Role::Admin->value);
    loginWeb($admin);

    for ($i = 0; $i < 9; $i++) {
        $this->travel(55)->minutes();
        $response = $this->get('/backoffice');
    }

    $response->assertRedirect(route('pin.unlock'));
});

it('quem ainda não tem PIN é levado a criá-lo', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession([]);
    $session = App\Modules\Auth\Models\AuthSession::query()->create(['user_id' => $user->id, 'type' => 'web', 'last_active_at' => now()]);
    $this->withSession([App\Modules\Auth\Services\AuthSessionService::SESSION_KEY => $session->id]);

    $this->get('/perfil')->assertRedirect(route('pin.setup'));
    $this->post('/pin/criar', ['pin' => '123456', 'pin_confirmation' => '123456'])->assertSessionHasErrors('pin');
    $this->post('/pin/criar', ['pin' => '739104', 'pin_confirmation' => '739104'])->assertRedirect();
    $this->get('/perfil')->assertOk();
    expect(Hash::check('739104', (string) $user->fresh()?->pin_hash))->toBeTrue();
});

it('"Usar outra conta" retira a confiança deste aparelho', function () {
    [, $token] = trustedUser();

    $this->withCookie('longa_device', $token)->post('/entrar/outra-conta')->assertRedirect(route('login'));

    expect(TrustedDevice::query()->sole()->revoked_reason)->toBe('other_account');
    $this->withCookie('longa_device', $token)->get('/entrar')->assertInertia(fn (Assert $p) => $p->component('Auth/Login'));
});
