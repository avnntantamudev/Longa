<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Vip\Pages\ListProviderSubscriptions;
use App\Modules\Notifications\Notifications\VipNotification;
use App\Modules\Payments\Models\ProviderSubscription;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Settings\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

function buyVip(ProviderProfile $provider, ?string $key = null): Illuminate\Testing\TestResponse
{
    apiLogin($provider->user);

    return test()->postJson('/api/v1/me/vip/purchase', ['method' => 'multicaixa_express', 'accept_terms' => true], ['Idempotency-Key' => $key ?? 'vip-'.uniqid()]);
}

it('o técnico compra o VIP: 50.000 Kz no ledger como receita de subscrições, válido 365 dias', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    apiLogin($provider->user);
    $this->getJson('/api/v1/me/vip')->assertJsonPath('data.is_vip', false)->assertJsonPath('data.price', 50000)->assertJsonPath('data.commission_percent', 10);

    buyVip($provider)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.amount', 50000);

    $ledger = app(LedgerService::class);
    expect($ledger->balance(Accounts::SUBSCRIPTION_REVENUE))->toBe(50000)
        ->and($ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(0)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and(app(VipSubscriptions::class)->activeUntil($provider)?->toDateString())->toBe(now()->addDays(365)->toDateString());
    $this->getJson('/api/v1/me/vip')->assertJsonPath('data.is_vip', true);
    Notification::assertSentTo($provider->user, VipNotification::class, fn ($n) => $n->kind === 'activated');
});

it('repetir com a mesma chave não cobra duas vezes; renovar soma ao fim atual', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    buyVip($provider, 'vip-key-1')->assertOk();
    buyVip($provider, 'vip-key-1')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect(ProviderSubscription::query()->count())->toBe(1);

    $this->travel(100)->days();
    buyVip($provider, 'vip-key-2')->assertOk();

    expect(app(VipSubscriptions::class)->activeUntil($provider)?->toDateString())->toBe(now()->subDays(100)->addDays(730)->toDateString())
        ->and(app(LedgerService::class)->balance(Accounts::SUBSCRIPTION_REVENUE))->toBe(100000);
});

it('pagamento recusado não ativa nem lança nada', function () {
    config(['longa.payments.mock_outcome' => 'failed']);
    $provider = ProviderProfile::factory()->verified()->create();

    buyVip($provider)->assertStatus(402)->assertJsonPath('error.code', 'payment_failed');

    expect(app(VipSubscriptions::class)->isVip($provider))->toBeFalse()
        ->and(ProviderSubscription::query()->sole()->status)->toBe('failed')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('usa o preço das Definições no momento da compra; exige verificação e aceitação das condições', function () {
    app(PlatformSettings::class)->update(['vip_price' => 70000], User::factory()->withRole(Role::Admin)->create());
    $provider = ProviderProfile::factory()->verified()->create();
    buyVip($provider)->assertOk()->assertJsonPath('data.amount', 70000);

    $unverified = ProviderProfile::factory()->create();
    buyVip($unverified)->assertStatus(409)->assertJsonPath('error.code', 'vip_not_allowed');

    apiLogin($provider->user);
    $this->postJson('/api/v1/me/vip/purchase', ['method' => 'reference'], ['Idempotency-Key' => 'vip-noterms'])->assertStatus(422);
    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson('/api/v1/me/vip')->assertForbidden();
});

it('o admin regista VIP pago em mão (ledger) ou cortesia (sem dinheiro)', function () {
    Filament::setCurrentPanel('backoffice');
    $cash = ProviderProfile::factory()->verified()->create();
    $courtesy = ProviderProfile::factory()->verified()->create();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ListProviderSubscriptions::class)
        ->callAction('grant', data: ['provider_profile_id' => $cash->id, 'source' => 'cash', 'note' => 'Pagou no escritório'])
        ->assertHasNoActionErrors()
        ->callAction('grant', data: ['provider_profile_id' => $courtesy->id, 'source' => 'courtesy', 'note' => 'Parceiro de lançamento'])
        ->assertHasNoActionErrors();

    $ledger = app(LedgerService::class);
    expect(app(VipSubscriptions::class)->isVip($cash))->toBeTrue()
        ->and(app(VipSubscriptions::class)->isVip($courtesy))->toBeTrue()
        ->and($ledger->balance(Accounts::SUBSCRIPTION_REVENUE))->toBe(50000)
        ->and($ledger->balance(Accounts::CASH_SETTLEMENT))->toBe(-50000);
});

it('avisa 30, 7 e 1 dia antes e no fim, cada aviso uma só vez', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    buyVip($provider)->assertOk();
    $end = app(VipSubscriptions::class)->activeUntil($provider);

    $kinds = function () use ($provider) {
        return Notification::sent($provider->user, VipNotification::class)->map(fn ($n) => $n->kind.':'.$n->daysLeft)->values()->all();
    };

    $this->travelTo($end?->copy()->subDays(29));
    $this->artisan('longa:vip:reminders');
    $this->artisan('longa:vip:reminders');
    $this->travelTo($end?->copy()->subDays(6));
    $this->artisan('longa:vip:reminders');
    $this->travelTo($end?->copy()->subHours(20));
    $this->artisan('longa:vip:reminders');
    $this->travelTo($end?->copy()->addHours(3));
    $this->artisan('longa:vip:reminders');
    $this->artisan('longa:vip:reminders');

    expect($kinds())->toBe(['activated:0', 'ending:29', 'ending:6', 'ending:1', 'ended:0'])
        ->and(app(VipSubscriptions::class)->isVip($provider))->toBeFalse();
});
