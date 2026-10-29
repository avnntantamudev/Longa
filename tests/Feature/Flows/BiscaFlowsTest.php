<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Notification;

/*
| BISCA, Fase 2 — critérios de aceitação: fluxo completo de um serviço de preço fixo e de um serviço por orçamento
| com adenda, de ponta a ponta pela API, e a corrida entre dois técnicos pelo mesmo pedido.
| (Transições válidas e inválidas: tests/Feature/Requests/StateMachineTest.php; despacho por rondas:
| tests/Feature/Matching/WavesTest.php; corrida entre processos reais: tests/Concurrency/AcceptOfferConcurrencyTest.php.)
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now()->setTime(8, 0));
    $this->client = User::factory()->withRole(Role::Client)->create();
});

function post(string $path, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->postJson($path, $body, ['Idempotency-Key' => 'flow-'.uniqid()]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function publish(Category $category, array $answers, array $extra = []): int
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    apiLogin(test()->client);

    return (int) test()->postJson('/api/v1/service-requests', [
        'category_id' => $category->id, 'answers' => $answers, 'scheduled_at' => now()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
        ...$extra,
    ])->assertCreated()->json('data.id');
}

function nearbyProvider(Category $category, int $meters = 800): ProviderProfile
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return ProviderProfile::factory()->verified()->near($lat, $lng, $meters)->withCategories($category)->create();
}

/**
 * @return list<string>
 */
function history(int $id): array
{
    return ServiceRequest::query()->findOrFail($id)->transitions()->get()->map(fn ($t) => $t->to_state->value)->all();
}

it('preço fixo de ponta a ponta: pedido, despacho, aceitação, pagamento, execução, confirmação e pagamento ao técnico', function () {
    $cleaning = Category::query()->where('slug', 'limpeza')->firstOrFail();
    $provider = nearbyProvider($cleaning);

    // 1. O cliente pede: o valor vem da tabela da categoria e fica bloqueado
    $id = publish($cleaning, ['descricao' => 'Apartamento T2 com varanda', 'tipologia' => 'T2']);
    expect(ServiceRequest::query()->findOrFail($id)->amount)->toBe(20000);

    // 2. Despacho: a oferta chega ao técnico com a distância e o valor que recebe
    apiLogin($provider->user);
    $offer = RequestOffer::query()->where('service_request_id', $id)->sole();
    $this->getJson("/api/v1/offers/{$offer->id}")->assertOk()->assertJsonPath('data.provider_net', 16000)->assertJsonPath('data.distance_m', 800);
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->assertJsonPath('data.state', 'accepted');

    // 3. O cliente paga (valor retido no escrow)
    apiLogin($this->client);
    post("/api/v1/sessions/{$id}/pay", ['method' => 'multicaixa_express'])->assertOk()->assertJsonPath('data.payment_status', 'held');

    // 4. Execução: a caminho → em execução → concluído
    apiLogin($provider->user);
    post("/api/v1/sessions/{$id}/on-the-way")->assertOk();
    $this->travelTo(now()->setTime(14, 0));
    apiLogin($provider->user);
    post("/api/v1/sessions/{$id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');
    post("/api/v1/sessions/{$id}/finish")->assertOk();

    // 5. O cliente confirma → pago; 80% para o técnico, 20% de comissão
    apiLogin($this->client);
    post("/api/v1/sessions/{$id}/confirm-without-qr")->assertOk()->assertJsonPath('data.state', 'paid');

    $ledger = app(LedgerService::class);
    expect(history($id))->toBe(['created', 'searching', 'accepted', 'on_the_way', 'in_progress', 'completed', 'paid'])
        ->and($ledger->balance(Accounts::providerAvailable($provider->id)))->toBe(16000)
        ->and($ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and(EscrowHold::query()->where('service_request_id', $id)->value('state'))->toBe(EscrowHold::RELEASED);
});

it('por orçamento com adenda de ponta a ponta: diagnóstico, orçamento aceite, trabalho extra aprovado e pago, confirmação', function () {
    $ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
    $provider = nearbyProvider($ac);

    $id = publish($ac, ['descricao' => 'O ar condicionado da sala não arrefece', 'aparelhos' => 1]);
    expect(ServiceRequest::query()->findOrFail($id)->only(['amount', 'visit_fee']))->toBe(['amount' => 0, 'visit_fee' => 5000]);

    apiLogin($provider->user);
    $offer = RequestOffer::query()->where('service_request_id', $id)->sole();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->assertJsonPath('data.payment_status', null);

    // Diagnóstico (taxa de visita paga em mão) e orçamento de 40.000 Kz
    post("/api/v1/sessions/{$id}/diagnosis")->assertOk()->assertJsonPath('data.state', 'diagnosing');
    $quote = post("/api/v1/sessions/{$id}/quotes", ['labour_amount' => 25000, 'materials_amount' => 15000, 'execution_days' => 1])
        ->assertCreated()->assertJsonPath('data.amount_due', 35000)->json('data.id');

    // O cliente aceita (preço bloqueado) e paga o total menos a taxa de visita
    apiLogin($this->client);
    post("/api/v1/quotes/{$quote}/accept")->assertOk()->assertJsonPath('data.state', 'quote_accepted')->assertJsonPath('data.amount', 35000);
    post("/api/v1/sessions/{$id}/pay", ['method' => 'multicaixa_express'])->assertOk();

    // Execução com trabalho extra de 6.000 Kz (15% do orçamento: sem revisão da equipa)
    apiLogin($provider->user);
    post("/api/v1/sessions/{$id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');
    $addendum = post("/api/v1/sessions/{$id}/addenda", ['description' => 'Trocar o suporte da unidade exterior', 'amount' => 6000])
        ->assertCreated()->assertJsonPath('data.team_review', false)->json('data.id');

    apiLogin($this->client);
    post("/api/v1/addenda/{$addendum}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    post("/api/v1/addenda/{$addendum}/pay", ['method' => 'multicaixa_express'])->assertOk()->assertJsonPath('data.status', 'paid');

    apiLogin($provider->user);
    post("/api/v1/sessions/{$id}/finish")->assertOk();
    apiLogin($this->client);
    post("/api/v1/sessions/{$id}/confirm-without-qr")->assertOk()->assertJsonPath('data.state', 'paid');

    // 41.000 Kz retidos e libertados: 32.800 para o técnico, 8.200 de comissão; mais a comissão da taxa de visita em dívida
    $ledger = app(LedgerService::class);
    expect(history($id))->toBe(['created', 'searching', 'accepted', 'on_the_way', 'diagnosing', 'quote_sent', 'quote_accepted', 'in_progress', 'completed', 'paid'])
        ->and(ServiceRequest::query()->findOrFail($id)->amount)->toBe(41000)
        ->and($ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and($ledger->balance(Accounts::providerCommissionDue($provider->id)) + $ledger->balance(Accounts::providerAvailable($provider->id)))->toBe(32800 - 1000);
});

it('corrida: dois técnicos aceitam o mesmo pedido; só o primeiro fica com ele e o outro perde a oferta', function () {
    $cleaning = Category::query()->where('slug', 'limpeza')->firstOrFail();
    $first = nearbyProvider($cleaning, 500);
    $second = nearbyProvider($cleaning, 900);
    $id = publish($cleaning, ['descricao' => 'Casa toda', 'tipologia' => 'T3']);

    $offers = RequestOffer::query()->where('service_request_id', $id)->get()->keyBy('provider_profile_id');
    expect($offers)->toHaveCount(2);

    apiLogin($first->user);
    $this->postJson("/api/v1/offers/{$offers[$first->id]->id}/accept")->assertOk();
    apiLogin($second->user);
    $this->postJson("/api/v1/offers/{$offers[$second->id]->id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'request_already_matched');

    $request = ServiceRequest::query()->findOrFail($id);
    expect($request->state)->toBe(RequestState::Accepted)
        ->and($request->provider_profile_id)->toBe($first->id)
        ->and($offers[$second->id]->fresh()?->state)->toBe(OfferState::Lost)
        ->and(RequestOffer::query()->where('service_request_id', $id)->where('state', OfferState::Accepted->value)->count())->toBe(1);
});
