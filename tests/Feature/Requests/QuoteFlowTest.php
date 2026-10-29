<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Illuminate\Support\Facades\Notification;

/*
| BISCA, Fase 2.5 (por orçamento): diagnóstico com taxa de visita paga em mão, orçamento (mão de obra, materiais,
| prazo, validade), aceitar (preço bloqueado, taxa descontada), pedir revisão ou recusar; orçamento expirado;
| alerta quando o total está muito fora do intervalo de referência.
*/

beforeEach(function () {
    Notification::fake();
    $this->ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
});

function quoteSession(string $paymentMethod = 'electronic'): ServiceRequest
{
    $session = acceptedSession($paymentMethod, ['category_id' => test()->ac->id]);
    // Por orçamento: sem pagamento antecipado, valor a 0 até haver orçamento aceite; taxa de visita da categoria
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'commission' => 0, 'provider_net' => 0, 'payment_status' => null, 'payment_due_at' => null, 'visit_fee' => 5000])->save();

    return $session->fresh() ?? $session;
}

function asProvider(ServiceRequest $session): void
{
    apiLogin($session->provider->user);
}

function sendQuote(ServiceRequest $session, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/sessions/{$session->id}/quotes", [
        'labour_amount' => 20000,
        'materials_amount' => 15000,
        'materials' => [['descricao' => 'Gás R410', 'quantidade' => 1, 'valor' => 15000]],
        'execution_days' => 1,
        'notes' => 'Recarga de gás e limpeza dos filtros.',
        ...$body,
    ], ['Idempotency-Key' => 'quote-'.uniqid()]);
}

function quoteAction(Quote|int $quote, string $action, array $body = []): Illuminate\Testing\TestResponse
{
    $id = $quote instanceof Quote ? $quote->id : $quote;

    return test()->postJson("/api/v1/quotes/{$id}/{$action}", $body, ['Idempotency-Key' => "{$action}-".uniqid()]);
}

it('diagnóstico: o técnico chega, a taxa de visita é paga em mão e a comissão sobre ela fica em dívida', function () {
    $session = quoteSession();
    asProvider($session);

    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['cancel', 'diagnosis']);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0001'])
        ->assertOk()->assertJsonPath('data.state', 'diagnosing')->assertJsonPath('data.actions', ['send_quote']);

    // Comissão de 20% sobre a taxa de 5.000 Kz
    expect(app(LedgerService::class)->balance(Accounts::providerCommissionDue($session->provider_profile_id)))->toBe(-1000)
        ->and($session->transitions()->get()->map(fn ($t) => $t->to_state->value)->all())->toBe(['created', 'on_the_way', 'diagnosing']);
});

it('fluxo: orçamento, revisão pedida pelo cliente, novo orçamento aceite com o preço bloqueado e a taxa descontada', function () {
    $session = quoteSession();
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0002'])->assertOk();

    $first = sendQuote($session)->assertCreated()
        ->assertJsonPath('data.total', 35000)->assertJsonPath('data.amount_due', 30000)->assertJsonPath('data.revision', 1)
        ->json('data.id');
    expect($session->fresh()?->state)->toBe(RequestState::QuoteSent)
        ->and(Quote::query()->findOrFail($first)->valid_until->isSameDay(now()->addDays(7)))->toBeTrue();

    // O técnico não responde ao próprio orçamento; o cliente pede revisão
    quoteAction($first, 'accept')->assertForbidden();
    apiLogin($session->client);
    quoteAction($first, 'revision', ['note' => 'O gás está caro'])->assertOk()->assertJsonPath('data.state', 'diagnosing');

    asProvider($session);
    $second = sendQuote($session, ['materials_amount' => 10000, 'materials' => []])->assertCreated()->assertJsonPath('data.revision', 2)->json('data.id');

    apiLogin($session->client);
    quoteAction($first, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'quote_not_open');
    quoteAction($second, 'accept')->assertOk()
        ->assertJsonPath('data.state', 'quote_accepted')
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.payment_status', 'pending')
        ->assertJsonPath('data.actions', ['pay']);

    // Preço bloqueado: aceitar outra vez não muda nada e já não se pode pedir revisão
    quoteAction($second, 'accept')->assertOk();
    quoteAction($second, 'revision')->assertStatus(409);

    // Paga (escrow) e o técnico executa
    app(App\Modules\Payments\Actions\PayForSession::class)->handle($session->fresh() ?? $session, $session->client, 'multicaixa_express');
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');

    $fresh = $session->fresh();
    expect([$fresh?->amount, $fresh?->commission, $fresh?->provider_net])->toBe([25000, 5000, 20000]);
});

it('recusar: o pedido é cancelado e o técnico fica com a taxa de visita', function () {
    $session = quoteSession('cash');
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0003'])->assertOk();
    $quote = sendQuote($session)->assertCreated()->json('data.id');

    apiLogin($session->client);
    quoteAction($quote, 'refuse', ['note' => 'Muito caro'])->assertOk()
        ->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.closed_reason', 'quote_refused');

    expect(Quote::query()->findOrFail($quote)->status)->toBe(Quote::REFUSED)
        ->and(app(LedgerService::class)->balance(Accounts::providerCommissionDue($session->provider_profile_id)))->toBe(-1000);
});

it('orçamento sem resposta até à validade: expira e o pedido é cancelado', function () {
    $session = quoteSession();
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0004'])->assertOk();
    $quote = sendQuote($session)->assertCreated()->json('data.id');

    $this->travel(8)->days();
    $this->artisan('longa:sessions:tick');

    expect(Quote::query()->findOrFail($quote)->status)->toBe(Quote::EXPIRED)
        ->and($session->fresh()?->state)->toBe(RequestState::Cancelled)
        ->and($session->fresh()?->closed_reason)->toBe('quote_expired');
});

it('marca para o painel os orçamentos muito fora do intervalo de referência', function () {
    $session = quoteSession();
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0005'])->assertOk();

    // Referência da categoria: 10.000 a 80.000 Kz; tolerância 50% → alerta acima de 120.000 Kz
    $quote = sendQuote($session, ['labour_amount' => 150000])->assertCreated()->json('data.id');

    expect(Quote::query()->findOrFail($quote)->outside_reference)->toBeTrue();
});

it('só nos serviços por orçamento; e nesses o técnico não inicia antes de o orçamento ser aceite', function () {
    $fixed = acceptedSession('cash');
    asProvider($fixed);
    $this->postJson("/api/v1/sessions/{$fixed->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0006'])
        ->assertStatus(409)->assertJsonPath('error.code', 'not_quote_request');

    $session = quoteSession('cash');
    $this->travelTo($session->scheduled_at);
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertStatus(409);
    $this->postJson("/api/v1/sessions/{$session->id}/quotes", ['labour_amount' => 1000, 'execution_days' => 1], ['Idempotency-Key' => 'quote-early-1'])
        ->assertStatus(409)->assertJsonPath('error.code', 'quote_not_expected');
});

it('o painel lista os orçamentos e alerta os que estão fora do intervalo (operador incluído)', function () {
    $session = quoteSession();
    asProvider($session);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-0007'])->assertOk();
    $outlier = Quote::query()->findOrFail(sendQuote($session, ['labour_amount' => 150000])->assertCreated()->json('data.id'));

    Filament\Facades\Filament::setCurrentPanel('backoffice');
    loginWeb(App\Modules\Users\Models\User::factory()->withRole(App\Modules\Users\Enums\Role::Operator)->create());

    expect(App\Modules\Admin\Filament\Resources\Quotes\QuoteResource::getNavigationBadge())->toBe('1');
    Livewire\Livewire::test(App\Modules\Admin\Filament\Resources\Quotes\Pages\ListQuotes::class)
        ->assertOk()
        ->filterTable('outside_reference', true)
        ->assertCanSeeTableRecords([$outlier]);
});
