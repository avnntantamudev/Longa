<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function act(ServiceRequest $session, string $as, string $action, array $body = []): Illuminate\Testing\TestResponse
{
    apiLogin($as === 'client' ? $session->client : $session->provider->user);

    return test()->postJson("/api/v1/sessions/{$session->id}/{$action}", $body, ['Idempotency-Key' => "{$action}-{$as}-".uniqid()]);
}

it('cliente cancela com antecedência: reembolso total', function () {
    $session = paidSession();

    act($session, 'client', 'cancel', ['reason' => 'Imprevisto'])->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.closed_reason', 'cancelled_by_client');

    // Reembolso executado no gateway (mock): refunds_payable → funds_in
    expect(balances($session))->toBe(['escrow' => 0, 'provider' => 0, 'revenue' => 0, 'refunds' => 0, 'funds_in' => 0])
        ->and(EscrowHold::query()->sole()->state)->toBe('refunded');
});

it('cliente cancela em cima da hora: o técnico fica com 25% menos comissão', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->subHours(2));

    act($session, 'client', 'cancel')->assertOk();

    // 25% de 25.000 = 6.250 → comissão 938, técnico 5.312; reembolso 18.750
    expect(balances($session))->toBe(['escrow' => 0, 'provider' => 5312, 'revenue' => 938, 'refunds' => 0, 'funds_in' => -6250])
        ->and(EscrowHold::query()->sole()->state)->toBe('partially_refunded');
});

it('técnico cancela: reembolso total e evento de score se for tarde', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->subHours(1));

    act($session, 'provider', 'cancel', ['reason' => 'Doença'])->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.closed_reason', 'cancelled_by_provider');

    expect(balances($session)['funds_in'])->toBe(0)
        ->and(ScoreEvent::query()->where('type', ScoreEvent::LATE_CANCELLATION)->count())->toBe(1);
});

it('não se cancela um serviço em curso', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);

    act($session, 'client', 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'session_not_cancellable');
});

it('falta do técnico sem contestação: reembolso total ao fim de 24 h e evento de score', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->addMinutes(10));
    act($session, 'client', 'no-show')->assertStatus(409)->assertJsonPath('error.code', 'no_show_too_early');

    $this->travelTo($session->scheduled_at->copy()->addMinutes(20));
    act($session, 'client', 'no-show')->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.closed_reason', 'no_show_provider');

    expect(balances($session)['escrow'])->toBe(25000); // retido durante o prazo de contestação

    $this->travel(25)->hours();
    $this->artisan('longa:sessions:tick')->assertSuccessful();
    $this->artisan('longa:sessions:tick')->assertSuccessful(); // idempotente

    expect(balances($session))->toBe(['escrow' => 0, 'provider' => 0, 'revenue' => 0, 'refunds' => 0, 'funds_in' => 0])
        ->and(ScoreEvent::query()->where('type', ScoreEvent::NO_SHOW)->count())->toBe(1);
});

it('falta do cliente sem contestação: o técnico fica com 50% menos comissão', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->addMinutes(35));
    act($session, 'provider', 'no-show')->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.closed_reason', 'no_show_client');

    $this->travel(25)->hours();
    $this->artisan('longa:sessions:tick');

    // 50% = 12.500 → comissão 1.875, técnico 10.625; reembolso 12.500
    expect(balances($session))->toBe(['escrow' => 0, 'provider' => 10625, 'revenue' => 1875, 'refunds' => 0, 'funds_in' => -12500]);
});

it('R1: serviço nunca iniciado → cancelado; sem contestação do técnico, reembolso total', function () {
    $session = paidSession();

    $this->travelTo($session->ends_at?->copy()->addHours(3));
    $this->artisan('longa:sessions:tick');
    expect($session->fresh()?->state)->toBe(RequestState::Cancelled)
        ->and($session->fresh()?->closed_reason)->toBe('not_started')
        ->and(balances($session)['escrow'])->toBe(25000);

    // O dinheiro só se move depois do prazo de contestação
    $this->travel(25)->hours();
    $this->artisan('longa:sessions:tick');

    expect(balances($session)['funds_in'])->toBe(0)
        ->and(SessionEvent::query()->where('service_request_id', $session->id)->where('type', 'no_show_settled')->count())->toBe(1);
});

it('R2: serviço iniciado sem QR e sem disputa → libertada para o técnico', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);

    $this->travelTo($session->ends_at?->copy()->addHours(3));
    $this->artisan('longa:sessions:tick');
    expect($session->fresh()?->state)->toBe(RequestState::Completed);

    $this->travel(49)->hours();
    $this->artisan('longa:sessions:tick');

    expect($session->fresh()?->state)->toBe(RequestState::Paid)
        ->and(balances($session))->toMatchArray(['escrow' => 0, 'provider' => 21250, 'revenue' => 3750]);
});

it('o cliente pode confirmar sem QR quando o serviço espera confirmação', function () {
    $session = paidSession();
    act($session, 'client', 'confirm-without-qr')->assertStatus(409);

    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    $this->travelTo($session->ends_at?->copy()->addHours(3));
    $this->artisan('longa:sessions:tick');

    act($session, 'client', 'confirm-without-qr')->assertOk()->assertJsonPath('data.state', 'paid');
    act($session, 'provider', 'confirm-without-qr')->assertForbidden();
    expect(balances($session)['provider'])->toBe(21250);
});

it('R5: serviço em dinheiro sem confirmação do cliente → comissão em dívida após 48 h', function () {
    $session = acceptedSession('cash');
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    $token = app(App\Modules\Sessions\Actions\FinishSession::class)->handle($session, $session->provider->user)['qr']['tokens'][0]['token'];
    app(App\Modules\Sessions\Actions\ConfirmSession::class)->handle($session, $session->client, $token, null);

    $this->travel(49)->hours();
    $this->artisan('longa:sessions:tick');

    expect(app(LedgerService::class)->balance(Accounts::providerCommissionDue($session->provider_profile_id)))->toBe(-3750)
        ->and($session->fresh()?->cash_confirmed_at)->not->toBeNull();
});
