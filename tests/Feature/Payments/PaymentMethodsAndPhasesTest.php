<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\PaymentPhase;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Illuminate\Support\Facades\Notification;

/*
| BISCA, Fase 3.2: interface única de pagamentos (Multicaixa Express, referência e cartão simulados com a mesma
| interface; dinheiro sem cobrança eletrónica) e pagamento por fases nos orçamentos grandes, com a comissão retida
| em cada fase.
*/

beforeEach(function () {
    Notification::fake();
    $this->ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
});

it('cada meio digital passa pelo seu fornecedor (simulado); o dinheiro não tem cobrança eletrónica', function () {
    $gateway = app(PaymentProvider::class);

    $mcx = $gateway->charge(1000, '+244923000001', 'multicaixa_express', 'k-mcx-1', 'teste');
    $ref = $gateway->charge(1000, '+244923000001', 'reference', 'k-ref-1', 'teste');
    $card = $gateway->charge(1000, '+244923000001', 'card', 'k-card-1', 'teste');
    $cash = $gateway->charge(1000, '+244923000001', 'cash', 'k-cash-1', 'teste');

    expect([$mcx->payload['driver'], $ref->payload['driver'], $card->payload['driver']])
        ->toBe(['multicaixa_express_simulated', 'reference_simulated', 'card_simulated'])
        ->and($ref->payload)->toHaveKeys(['entity', 'reference', 'amount'])
        ->and($cash->succeeded())->toBeFalse()
        ->and($gateway->refund((string) $card->reference, 500, 'k-rfd-1')->reference)->toStartWith('CARD-RFD-');
});

it('o cliente pode pagar o serviço com cartão', function () {
    $session = acceptedSession('electronic');
    apiLogin($session->client);

    $this->postJson("/api/v1/sessions/{$session->id}/pay", ['method' => 'card'], ['Idempotency-Key' => 'pay-card-001'])
        ->assertOk()->assertJsonPath('data.payment_status', 'held');
});

/**
 * Orçamento grande aceite com fases 30/40/30: total 200.000 Kz, taxa de visita 5.000 → 195.000 a pagar.
 */
function phasedSession(): ServiceRequest
{
    $session = acceptedSession('electronic', ['category_id' => test()->ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'commission' => 0, 'provider_net' => 0, 'payment_status' => null, 'payment_due_at' => null, 'visit_fee' => 5000, 'commission_percent' => 20])->save();
    apiLogin($session->provider->user);
    test()->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-'.uniqid()])->assertOk();
    $quote = test()->postJson("/api/v1/sessions/{$session->id}/quotes", ['labour_amount' => 150000, 'materials_amount' => 50000, 'execution_days' => 10, 'phased' => true], ['Idempotency-Key' => 'q-'.uniqid()])
        ->assertCreated()->assertJsonPath('data.phases', [30, 40, 30])->json('data.id');

    apiLogin($session->client);
    test()->postJson("/api/v1/quotes/{$quote}/accept", [], ['Idempotency-Key' => 'acc-'.uniqid()])->assertOk();

    return $session->fresh() ?? $session;
}

function payNow(ServiceRequest $session): Illuminate\Testing\TestResponse
{
    apiLogin($session->client);

    return test()->postJson("/api/v1/sessions/{$session->id}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => 'pay-'.uniqid()]);
}

it('pagamento por fases: cada fase é paga, retida e libertada ao técnico com a comissão quando o cliente a confirma', function () {
    $session = phasedSession();
    $phases = PaymentPhase::query()->where('service_request_id', $session->id)->orderBy('sequence')->get();
    expect($phases->map(fn ($p) => [$p->percent, $p->amount, $p->status])->all())
        ->toBe([[30, 58500, 'due'], [40, 78000, 'pending'], [30, 58500, 'pending']]);

    $ledger = app(LedgerService::class);
    $provider = Accounts::providerAvailable($session->provider_profile_id);

    // Fase 1: paga e retida; o técnico começa
    payNow($session)->assertOk()->assertJsonPath('data.payment_status', 'held');
    expect($ledger->balance(Accounts::ESCROW))->toBe(58500);
    apiLogin($session->provider->user);
    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertOk();
    $this->postJson("/api/v1/sessions/{$session->id}/finish")->assertStatus(409)->assertJsonPath('error.code', 'phases_pending');

    // O cliente confirma a fase 1: 80% para o técnico, 20% de comissão; a fase 2 fica a pagar
    apiLogin($session->client);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['confirm_phase', 'dispute']);
    $this->postJson("/api/v1/phases/{$phases[0]->id}/confirm", [], ['Idempotency-Key' => 'phase-1-confirm'])->assertOk();
    expect($ledger->balance($provider))->toBe(46800)
        ->and($phases[1]->fresh()?->status)->toBe(PaymentPhase::DUE);

    // A última fase não se confirma à parte
    $this->postJson("/api/v1/phases/{$phases[2]->id}/confirm", [], ['Idempotency-Key' => 'phase-3-early'])->assertStatus(409);

    payNow($session)->assertOk();
    $this->postJson("/api/v1/phases/{$phases[1]->id}/confirm", [], ['Idempotency-Key' => 'phase-2-confirm'])->assertOk();
    payNow($session)->assertOk();
    expect($ledger->balance(Accounts::ESCROW))->toBe(58500);

    // Fim: o técnico conclui e o cliente confirma o serviço → a última fase é libertada
    apiLogin($session->provider->user);
    $this->postJson("/api/v1/sessions/{$session->id}/finish")->assertOk();
    apiLogin($session->client);
    $this->postJson("/api/v1/sessions/{$session->id}/confirm-without-qr", [], ['Idempotency-Key' => 'final-confirm'])->assertOk()->assertJsonPath('data.state', 'paid');

    expect($ledger->balance(Accounts::ESCROW))->toBe(0)
        // 80% de 195.000 = 156.000, menos a comissão da taxa de visita (1.000) descontada do saldo
        ->and($ledger->balance($provider))->toBe(155000)
        ->and($ledger->balance($provider))->toBe($ledger->balanceFromEntries($provider));
});

it('só a partir do valor mínimo (150.000 Kz) e com fases válidas', function () {
    $session = acceptedSession('electronic', ['category_id' => $this->ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'payment_status' => null, 'payment_due_at' => null])->save();
    apiLogin($session->provider->user);
    $this->postJson("/api/v1/sessions/{$session->id}/diagnosis", [], ['Idempotency-Key' => 'diag-small1'])->assertOk();

    $this->postJson("/api/v1/sessions/{$session->id}/quotes", ['labour_amount' => 50000, 'execution_days' => 1, 'phased' => true], ['Idempotency-Key' => 'q-small-01'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['phases']]]);
    $this->postJson("/api/v1/sessions/{$session->id}/quotes", ['labour_amount' => 200000, 'execution_days' => 1, 'phased' => true, 'phases' => [60, 30]], ['Idempotency-Key' => 'q-bad-sum1'])
        ->assertUnprocessable();
    $this->postJson("/api/v1/sessions/{$session->id}/quotes", ['labour_amount' => 200000, 'execution_days' => 1, 'phased' => true, 'phases' => [50, 50]], ['Idempotency-Key' => 'q-ok-0001'])
        ->assertCreated()->assertJsonPath('data.phases', [50, 50]);
});
