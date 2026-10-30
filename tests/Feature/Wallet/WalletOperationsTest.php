<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Admin\Filament\Resources\Ledger\Pages\ListLedgerTransactions;
use App\Modules\Admin\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Withdrawal;
use Database\Factories\ProviderProfileFactory;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| BISCA, Fase 3.1: carteira do técnico. Movimentos imutáveis por tipo (crédito de serviço, débito de comissão,
| recarga, levantamento, estorno, ajuste); saldo = soma dos movimentos; saldo mínimo para receber pedidos.
*/

beforeEach(function () {
    $this->provider = ProviderProfile::factory()->verified()->create();
    $this->ledger = app(LedgerService::class);
});

function owe(ProviderProfile $provider, int $amount): void
{
    app(LedgerService::class)->post('cash_commission_due', 'test:due:'.uniqid(), [
        Accounts::PLATFORM_REVENUE => $amount,
        Accounts::providerCommissionDue($provider) => -$amount,
    ]);
}

function credit(ProviderProfile $provider, int $amount): void
{
    app(LedgerService::class)->post('escrow_release', 'test:credit:'.uniqid(), [
        Accounts::providerAvailable($provider) => $amount,
        Accounts::ESCROW => -$amount,
    ]);
}

it('recarga: o valor entra no saldo, paga logo a dívida de comissões e repetir a mesma chave não cobra outra vez', function () {
    owe($this->provider, 3000);
    apiLogin($this->provider->user);

    $this->postJson('/api/v1/wallet/top-ups', ['amount' => 10000, 'method' => 'multicaixa_express'], ['Idempotency-Key' => 'topup-0001'])
        ->assertCreated()->assertJsonPath('data.available', 7000)->assertJsonPath('data.commission_due', 0);
    $this->postJson('/api/v1/wallet/top-ups', ['amount' => 10000, 'method' => 'multicaixa_express'], ['Idempotency-Key' => 'topup-0001'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($this->ledger->balance(Accounts::providerAvailable($this->provider)))->toBe(7000)
        ->and(AuditLog::query()->where('action', 'wallet.topped_up')->count())->toBe(1);

    $kinds = collect($this->getJson('/api/v1/wallet/movements')->json('data'))->pluck('kind')->unique()->sort()->values()->all();
    expect($kinds)->toBe(['commission_debit', 'topup']);
});

it('levantamento: mínimo de 5.000 Kz, só com saldo; o valor fica retido até a equipa pagar', function () {
    credit($this->provider, 20000);
    apiLogin($this->provider->user);

    $this->postJson('/api/v1/wallet/withdrawals', ['amount' => 4000, 'destination' => 'AO06004400006729503010102'], ['Idempotency-Key' => 'wd-min-1'])
        ->assertUnprocessable();
    $this->postJson('/api/v1/wallet/withdrawals', ['amount' => 25000, 'destination' => 'AO06004400006729503010102'], ['Idempotency-Key' => 'wd-big-1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_balance');
    $id = $this->postJson('/api/v1/wallet/withdrawals', ['amount' => 15000, 'destination' => 'AO06004400006729503010102'], ['Idempotency-Key' => 'wd-ok-0001'])
        ->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');

    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', 5000)->assertJsonPath('data.pending_withdrawals', 15000);

    // Só o admin decide; o operador nem vê a lista
    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    expect(App\Modules\Admin\Filament\Resources\Withdrawals\WithdrawalResource::canAccess())->toBeFalse();

    loginWeb(User::factory()->withRole(Role::Admin)->create());
    $withdrawal = Withdrawal::query()->findOrFail($id);
    Livewire::test(ListWithdrawals::class)->callTableAction('pay', $withdrawal, data: ['reference' => 'TRF-2026-0001'])->assertHasNoTableActionErrors();

    expect($withdrawal->fresh()?->status)->toBe(Withdrawal::PAID)
        ->and($this->ledger->balance(Accounts::WITHDRAWALS_PENDING))->toBe(0)
        ->and($this->ledger->balance(Accounts::PAYOUTS))->toBe(15000)
        ->and(AuditLog::query()->where('action', 'wallet.withdrawal_paid')->exists())->toBeTrue();
});

it('levantamento recusado: o valor volta ao saldo por estorno', function () {
    credit($this->provider, 20000);
    apiLogin($this->provider->user);
    $id = $this->postJson('/api/v1/wallet/withdrawals', ['amount' => 10000, 'destination' => '923456789'], ['Idempotency-Key' => 'wd-rej-1'])->json('data.id');

    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ListWithdrawals::class)->callTableAction('reject', Withdrawal::query()->findOrFail($id), data: ['note' => 'IBAN não corresponde ao titular'])->assertHasNoTableActionErrors();

    expect($this->ledger->balance(Accounts::providerAvailable($this->provider)))->toBe(20000);
    apiLogin($this->provider->user);
    expect(collect($this->getJson('/api/v1/wallet/movements')->json('data'))->pluck('kind')->all())->toContain('reversal', 'withdrawal');
});

it('ajuste da equipa: movimento novo com motivo e auditoria; os movimentos anteriores não mudam', function () {
    credit($this->provider, 5000);
    $before = LedgerEntry::query()->orderBy('id')->get(['id', 'amount'])->toArray();

    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ListLedgerTransactions::class)
        ->callAction('adjust', data: ['provider_profile_id' => $this->provider->id, 'amount' => -1500, 'reason' => 'Correção de serviço duplicado'])
        ->assertHasNoActionErrors();

    expect($this->ledger->balance(Accounts::providerAvailable($this->provider)))->toBe(3500)
        ->and(LedgerEntry::query()->orderBy('id')->limit(count($before))->get(['id', 'amount'])->toArray())->toBe($before)
        ->and(AuditLog::query()->where('action', 'wallet.adjusted')->value('metadata'))->toMatchArray(['amount' => -1500]);
});

it('o saldo é sempre a soma dos movimentos', function () {
    owe($this->provider, 2000);
    credit($this->provider, 30000);
    app(App\Modules\Wallet\Services\WalletOperations::class)->topUp($this->provider, 5000, 'reference', 'k-1');
    app(App\Modules\Wallet\Services\WalletOperations::class)->requestWithdrawal($this->provider, 8000, '923456789');

    foreach ([Accounts::providerAvailable($this->provider), Accounts::providerCommissionDue($this->provider), Accounts::WITHDRAWALS_PENDING, Accounts::FUNDS_IN] as $code) {
        expect($this->ledger->balance($code))->toBe($this->ledger->balanceFromEntries($code), $code);
    }
});

it('saldo mínimo: quem deve mais de 5.000 Kz deixa de receber pedidos até recarregar', function () {
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, 300)->withCategories($sgme)->create();
    $request = ServiceRequest::factory()->forCategory($sgme)->create(['max_hourly_rate' => 20000, 'pricing_mode' => 'hourly_package']);
    $matched = fn () => array_map(fn ($c) => $c->providerProfileId, app(ProviderMatcher::class)->candidates($request, 10, radiusM: 5000));

    owe($provider, 5000);
    expect($matched())->toContain($provider->id);

    owe($provider, 1000);
    apiLogin($provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.net_balance', -6000)->assertJsonPath('data.receiving_requests', false);
    expect($matched())->not->toContain($provider->id);

    app(App\Modules\Wallet\Services\WalletOperations::class)->topUp($provider, 2000, 'multicaixa_express', 'k-min');
    expect($matched())->toContain($provider->id);
});
