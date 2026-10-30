<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Admin\Filament\Pages\Alerts;
use App\Modules\Admin\Filament\Resources\Audit\AuditLogResource;
use App\Modules\Admin\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Modules\Admin\Filament\Resources\Requests\Pages\ListServiceRequests;
use App\Modules\Admin\Filament\Widgets\FinanceOverview;
use App\Modules\Admin\Services\PlatformAlerts;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| BISCA, Fase 3.6: pedidos em tempo real com filtros, finanças, alertas (orçamentos, mensagens, desvios, saldo baixo)
| e registo de auditoria.
*/

beforeEach(function () {
    Filament::setCurrentPanel('backoffice');
});

function cancelledSoonAfterAccepting(ProviderProfile $provider, int $minutesAfter): ServiceRequest
{
    $session = acceptedSession('cash', ['provider_profile_id' => $provider->id]);
    $session->forceFill(['state' => RequestState::Cancelled, 'closed_reason' => 'cancelled_by_provider', 'matched_at' => now()->subMinutes($minutesAfter), 'cancelled_at' => now()])->save();

    return $session;
}

it('lista os pedidos em tempo real e filtra por estado, zona e categoria', function () {
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    $talatona = ServiceRequest::factory()->create(['municipality' => 'Talatona', 'neighborhood' => 'Benfica']);
    $viana = ServiceRequest::factory()->create(['municipality' => 'Viana', 'neighborhood' => 'Zango']);
    $viana->forceFill(['state' => RequestState::Searching])->save();

    Livewire::test(ListServiceRequests::class)->assertOk()->assertCanSeeTableRecords([$talatona, $viana])
        ->filterTable('state', ['searching'])->assertCanSeeTableRecords([$viana])->assertCanNotSeeTableRecords([$talatona]);
    Livewire::test(ListServiceRequests::class)
        ->filterTable('zone', ['zone' => 'Zango'])->assertCanSeeTableRecords([$viana])->assertCanNotSeeTableRecords([$talatona]);
    Livewire::test(ListServiceRequests::class)
        ->filterTable('category_id', $talatona->category_id)->assertCanSeeTableRecords([$talatona, $viana]);
});

it('alerta 3 ou mais cancelamentos logo depois de aceitar; os mais tardios não contam', function () {
    $suspect = ProviderProfile::factory()->verified()->create();
    foreach (range(1, 3) as $i) {
        cancelledSoonAfterAccepting($suspect, 30);
    }
    $normal = ProviderProfile::factory()->verified()->create();
    cancelledSoonAfterAccepting($normal, 30);
    foreach (range(1, 3) as $i) {
        cancelledSoonAfterAccepting($normal, 600);
    }

    expect(app(PlatformAlerts::class)->suspiciousCancellations())
        ->toBe([['name' => (string) $suspect->user->name, 'phone' => (string) $suspect->user->phone, 'cancellations' => 3]]);
});

it('a página de alertas mostra os técnicos com saldo negativo a quem o operador tem acesso', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    app(LedgerService::class)->post('cash_commission_due', 'panel:due:1', [
        Accounts::PLATFORM_REVENUE => 6000,
        Accounts::providerCommissionDue($provider) => -6000,
    ]);

    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(Alerts::class)->assertOk()->assertSee($provider->user->name)->assertSee('-6.000')->assertSee('Não');
});

it('as finanças mostram saldos das carteiras e levantamentos pendentes, só ao admin', function () {
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    expect(FinanceOverview::canView())->toBeFalse();

    loginWeb(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(FinanceOverview::class)->assertOk()->assertSee('Saldos das carteiras')->assertSee('Levantamentos pendentes');
});

it('o registo de auditoria é só para o admin', function () {
    $log = AuditLog::query()->forceCreate(['action' => 'wallet.adjusted', 'metadata' => ['amount' => 100]]);

    loginWeb(User::factory()->withRole(Role::Operator)->create());
    expect(AuditLogResource::canAccess())->toBeFalse();

    loginWeb(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ListAuditLogs::class)->assertOk()->assertCanSeeTableRecords([$log])->assertSee('wallet.adjusted');
});
