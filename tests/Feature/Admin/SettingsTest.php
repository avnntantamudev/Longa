<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Admin\Filament\Pages\Settings;
use App\Modules\Requests\Services\Commission;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Support\Settings\PlatformSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('backoffice'));

it('sem valores gravados usa os de config (VIP 50.000 Kz, 10%)', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->int('vip_price'))->toBe(50000)
        ->and($settings->int('vip_commission_percent'))->toBe(10)
        ->and($settings->int('commission_percent'))->toBe(15);
});

it('o admin muda as definições no backoffice; fica auditado e vale para novas operações', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    loginWeb($admin);

    Livewire::test(Settings::class)
        ->assertSet('data.vip_price', 50000)
        ->set('data.vip_price', 60000)
        ->set('data.commission_percent', 12)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(PlatformSettings::class)->int('vip_price'))->toBe(60000)
        ->and(Commission::split(10000)['commission'])->toBe(1200);

    $log = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($log->metadata)->toMatchArray(['vip_price' => ['from' => 50000, 'to' => 60000], 'commission_percent' => ['from' => 15, 'to' => 12]]);
});

it('recusa valores fora dos limites', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(PlatformSettings::class)->update(['commission_percent' => 80], $admin))->toThrow(InvalidArgumentException::class)
        ->and(app(PlatformSettings::class)->int('commission_percent'))->toBe(15);
});

it('só administradores abrem as definições', function () {
    $this->actingAs(User::factory()->withRole(Role::Trainer)->create())->get('/backoffice/definicoes')->assertForbidden();
    loginWeb(User::factory()->withRole(Role::Admin)->create());
    $this->get('/backoffice/definicoes')->assertOk()->assertSee('Preço VIP por ano');
});
