<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Admin\Filament\Pages\Reports;
use App\Modules\Admin\Filament\Pages\Settings;
use App\Modules\Admin\Filament\Resources\CategorySuggestions\CategorySuggestionResource;
use App\Modules\Admin\Filament\Resources\Commissions\CommissionDueResource;
use App\Modules\Admin\Filament\Resources\Disputes\DisputeResource;
use App\Modules\Admin\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Modules\Admin\Filament\Resources\Ledger\LedgerTransactionResource;
use App\Modules\Admin\Filament\Resources\Sessions\ServiceJobResource;
use App\Modules\Admin\Filament\Resources\Vip\ProviderSubscriptionResource;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
| Equipa do BISCA: o admin gere dinheiro e definições; o operador trata da operação no backoffice.
*/

beforeEach(function () {
    Cache::flush();
    Filament::setCurrentPanel('backoffice');
});

it('o operador entra no backoffice mas não vê as finanças', function () {
    loginWeb(User::factory()->withRole(Role::Operator)->create());

    $this->get('/backoffice')->assertOk()->assertDontSee('Retido em escrow');

    expect(ServiceJobResource::canAccess())->toBeTrue()
        ->and(DisputeResource::canAccess())->toBeTrue()
        ->and(CategorySuggestionResource::canAccess())->toBeTrue()
        ->and(Settings::canAccess())->toBeFalse()
        ->and(Reports::canAccess())->toBeFalse()
        ->and(CommissionDueResource::canAccess())->toBeFalse()
        ->and(LedgerTransactionResource::canAccess())->toBeFalse()
        ->and(ProviderSubscriptionResource::canAccess())->toBeFalse();

    $this->get(Settings::getUrl())->assertForbidden();
});

it('o operador vê a disputa mas só o admin a decide', function () {
    $session = paidSession();
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session, $session->provider->user);
    $dispute = app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->client, 'quality', 'O serviço ficou muito aquém do combinado.');

    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->assertOk()->assertActionHidden('release');

    apiLogin(User::factory()->withRole(Role::Operator)->create());
    $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['decision' => 'release', 'note' => 'Teste do operador'], ['Idempotency-Key' => 'op-1'])
        ->assertForbidden();

    loginWeb(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->assertActionVisible('release');
});

it('o operador revê verificações de técnicos', function () {
    $operator = User::factory()->withRole(Role::Operator)->create();
    apiLogin($operator);
    $this->getJson('/api/v1/admin/verifications')->assertOk();

    loginWeb($operator);
    $this->get('/admin/verificacoes')->assertOk();
});

it('clientes e técnicos não entram no backoffice', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())->get('/backoffice')->assertForbidden();
})->with([Role::Client, Role::Provider, Role::CompanyRh]);

it('o comando longa:team dá e tira o papel e fica na auditoria', function () {
    $user = User::factory()->create(['phone' => '+244923111222']);

    $this->artisan('longa:team', ['phone' => '923 111 222', 'role' => 'operator'])->assertSuccessful();
    expect($user->fresh()?->isTeam())->toBeTrue()
        ->and($user->fresh()?->isAdmin())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'team.role_assigned')->where('subject_id', (string) $user->id)->exists())->toBeTrue();

    $this->artisan('longa:team', ['phone' => '923111222', 'role' => 'operator', '--remove' => true])->assertSuccessful();
    expect($user->fresh()?->isTeam())->toBeFalse();

    $this->artisan('longa:team', ['phone' => '923111222', 'role' => 'client'])->assertExitCode(2);
    $this->artisan('longa:team', ['phone' => '923999999'])->assertFailed();
});
