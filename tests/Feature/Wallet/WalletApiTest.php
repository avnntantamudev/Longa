<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function completeWithQr(ServiceRequest $session): void
{
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    $token = app(FinishSession::class)->handle($session, $session->provider->user)['qr']['tokens'][0]['token'];
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->client, $token, null);
}

it('carteira vazia de um técnico novo', function () {
    $provider = App\Modules\Users\Models\ProviderProfile::factory()->verified()->create();
    apiLogin($provider->user);

    $this->getJson('/api/v1/wallet')->assertOk()
        ->assertExactJson(['data' => ['available' => 0, 'pending' => 0, 'total_earned' => 0, 'commission_due' => 0, 'currency' => 'AOA']]);
    $this->getJson('/api/v1/wallet/movements')->assertOk()->assertJsonCount(0, 'data');
});

it('pendente enquanto retido, disponível depois da confirmação', function () {
    $session = paidSession();
    apiLogin($session->provider->user);

    $this->getJson('/api/v1/wallet')->assertJsonPath('data.pending', 21250)->assertJsonPath('data.available', 0);

    completeWithQr($session);
    apiLogin($session->provider->user);

    $this->getJson('/api/v1/wallet')->assertJsonPath('data.pending', 0)
        ->assertJsonPath('data.available', 21250)
        ->assertJsonPath('data.total_earned', 21250);
    $this->getJson('/api/v1/wallet/movements')->assertOk()
        ->assertJsonPath('data.0.type', 'escrow_release')
        ->assertJsonPath('data.0.label', 'Serviço confirmado')
        ->assertJsonPath('data.0.amount', 21250)
        ->assertJsonPath('data.0.session_id', $session->id);
});

it('comissão de serviço em mão é descontada no serviço eletrónica seguinte', function () {
    $cash = acceptedSession('cash');
    completeWithQr($cash);
    app(ConfirmCashPayment::class)->handle($cash->fresh() ?? $cash, $cash->client);

    apiLogin($cash->provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.commission_due', 3750);

    $electronic = paidSession(['provider_profile_id' => $cash->provider_profile_id, 'scheduled_at' => now()->addDays(2)]);
    completeWithQr($electronic);

    apiLogin($cash->provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.commission_due', 0)
        ->assertJsonPath('data.available', 21250 - 3750);
    expect(collect($this->getJson('/api/v1/wallet/movements')->json('data'))->pluck('type')->all())
        ->toContain('commission_offset', 'cash_commission_due', 'escrow_release');
});

it('só técnicos têm carteira e cada um só vê a sua', function () {
    $session = paidSession();
    completeWithQr($session);

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson('/api/v1/wallet')->assertForbidden();

    apiLogin(App\Modules\Users\Models\ProviderProfile::factory()->verified()->create()->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', 0);
});

it('a carteira exige autenticação', function () {
    $this->getJson('/api/v1/wallet')->assertUnauthorized();
});

it('linha do tempo e ações disponíveis do serviço', function () {
    $session = paidSession();
    apiLogin($session->client);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['cancel'])
        ->assertJsonPath('data.state_label', 'Aceite');

    $this->travelTo($session->scheduled_at);
    apiLogin($session->provider->user);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['start', 'cancel']);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session, $session->provider->user);

    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['show_qr', 'dispute']);
    apiLogin($session->client);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['confirm', 'confirm_without_qr', 'dispute']);

    $types = collect($this->getJson("/api/v1/sessions/{$session->id}/events")->assertOk()->json('data'))->pluck('type')->all();
    expect($types)->toContain('payment_confirmed', 'started', 'finished', 'qr_issued');

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson("/api/v1/sessions/{$session->id}/events")->assertForbidden();
});
