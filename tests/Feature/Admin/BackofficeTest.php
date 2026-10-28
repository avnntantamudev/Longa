<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Commissions\Pages\ListCommissionsDue;
use App\Modules\Admin\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Modules\Admin\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Modules\Admin\Filament\Resources\Reconciliations\Pages\ListLedgerReconciliations;
use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerReconciliation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Filament::setCurrentPanel('backoffice');
});

function disputedSession(): Dispute
{
    $session = paidSession();
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session, $session->provider->user);

    return app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->client, 'quality', 'A aula ficou muito aquém do combinado.');
}

function backofficeAdmin(): User
{
    $admin = User::factory()->withRole(Role::Admin)->create();
    loginWeb($admin);

    return $admin;
}

it('só administradores entram no backoffice', function () {
    $this->get('/backoffice')->assertRedirect('/entrar');

    $this->actingAs(User::factory()->withRole(Role::Client)->create())->get('/backoffice')->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::Admin)->suspended()->create())->get('/backoffice')->assertForbidden();

    backofficeAdmin();
    $this->get('/backoffice')->assertOk()->assertSee('Retido em escrow');
});

it('lista disputas abertas e mostra a linha do tempo da aula', function () {
    $dispute = disputedSession();
    backofficeAdmin();

    Livewire::test(ListDisputes::class)->assertCanSeeTableRecords([$dispute]);
    $this->get("/backoffice/disputas/{$dispute->id}")->assertOk()
        ->assertSee('A aula ficou muito aquém do combinado.')
        ->assertSee('dispute_opened')
        ->assertSee('escrow_hold');
});

it('decidir exige nota e a palavra CONFIRMAR; depois move o dinheiro uma vez', function () {
    $dispute = disputedSession();
    backofficeAdmin();

    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
        ->callAction('release', data: ['note' => 'Evidências a favor do formador.', 'confirmation' => 'confirmo'])
        ->assertHasActionErrors(['confirmation']);
    expect($dispute->fresh()?->status)->toBe(Dispute::OPEN);

    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
        ->callAction('release', data: ['note' => 'Evidências a favor do formador.', 'confirmation' => 'CONFIRMAR'])
        ->assertHasNoActionErrors();

    expect($dispute->fresh()?->resolution)->toBe('released')
        ->and($dispute->session->fresh()?->state)->toBe(SessionState::Completed)
        ->and(balances($dispute->session)['provider'])->toBe(21250);

    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->assertActionHidden('refund');
});

it('reembolso parcial pelo backoffice valida a parte do formador', function () {
    $dispute = disputedSession();
    backofficeAdmin();

    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
        ->callAction('partial', data: ['provider_share' => 25000, 'note' => 'Metade da aula.', 'confirmation' => 'CONFIRMAR'])
        ->assertHasActionErrors(['provider_share']);

    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
        ->callAction('partial', data: ['provider_share' => 12500, 'note' => 'Metade da aula.', 'confirmation' => 'CONFIRMAR'])
        ->assertHasNoActionErrors();

    expect(balances($dispute->session))->toMatchArray(['provider' => 10625, 'revenue' => 1875, 'escrow' => 0]);
});

it('marcar comissão em mão como recebida', function () {
    $session = acceptedSession('cash');
    app(CommissionDebts::class)->register($session, null, 'test');
    $account = LedgerAccount::query()->where('code', Accounts::providerCommissionDue($session->provider_profile_id))->sole();
    backofficeAdmin();

    Livewire::test(ListCommissionsDue::class)
        ->assertCanSeeTableRecords([$account])
        ->callTableAction('receive', $account, data: ['amount' => 5000, 'key' => 'k1'])
        ->assertHasTableActionErrors(['amount']);

    Livewire::test(ListCommissionsDue::class)
        ->callTableAction('receive', $account, data: ['amount' => 3750, 'key' => 'k2'])
        ->assertHasNoTableActionErrors();

    expect(app(CommissionDebts::class)->outstanding($session->provider_profile_id))->toBe(0);
    Livewire::test(ListCommissionsDue::class)->assertCanNotSeeTableRecords([$account]);
});

it('a reconciliação pode ser corrida a pedido', function () {
    paidSession();
    backofficeAdmin();

    Livewire::test(ListLedgerReconciliations::class)->callAction('run');

    expect(LedgerReconciliation::query()->sole()->status)->toBe('ok');
});
