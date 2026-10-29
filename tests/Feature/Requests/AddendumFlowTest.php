<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Addenda\Pages\ListAddenda;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| BISCA, Fase 2.5: adendas ao orçamento aceite. O técnico propõe o trabalho extra durante a execução, o cliente
| aprova antes de ser feito e, acima de 20% do orçamento original (somando as adendas aprovadas), a equipa revê.
*/

beforeEach(function () {
    Notification::fake();
    $this->ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
});

/**
 * Pedido por orçamento em execução: orçamento de 50.000 Kz aceite (taxa de visita de 5.000 já paga → 45.000 a pagar).
 */
function inProgressQuote(string $paymentMethod = 'electronic'): ServiceRequest
{
    $session = acceptedSession($paymentMethod, ['category_id' => test()->ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'commission' => 0, 'provider_net' => 0, 'payment_status' => null, 'payment_due_at' => null, 'visit_fee' => 5000])->save();
    $provider = $session->provider->user;
    $flow = app(QuoteFlow::class);

    $flow->startDiagnosis($session, $provider);
    $quote = $flow->submit($session, $provider, ['labour_amount' => 40000, 'materials_amount' => 10000, 'execution_days' => 1]);
    $flow->accept($quote, $session->client);
    if ($paymentMethod === 'electronic') {
        app(PayForSession::class)->handle($session->fresh() ?? $session, $session->client, 'multicaixa_express');
    }
    app(StartSession::class)->handle($session->fresh() ?? $session, $provider);

    return $session->fresh() ?? $session;
}

function proposeAddendum(ServiceRequest $session, int $amount): Illuminate\Testing\TestResponse
{
    apiLogin($session->provider->user);

    return test()->postJson("/api/v1/sessions/{$session->id}/addenda", ['description' => 'Substituir o condensador', 'amount' => $amount], ['Idempotency-Key' => 'add-'.uniqid()]);
}

function addendumAction(int $id, string $action, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/addenda/{$id}/{$action}", $body, ['Idempotency-Key' => "{$action}-".uniqid()]);
}

it('adenda até 20%: o cliente aprova, o valor sobe e o pagamento junta-se ao escrow', function () {
    $session = inProgressQuote();
    expect($session->amount)->toBe(45000);

    apiLogin($session->provider->user);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['finish', 'dispute', 'addendum']);
    $id = proposeAddendum($session, 8000)->assertCreated()
        ->assertJsonPath('data.status', 'pending_client')->assertJsonPath('data.percent_of_quote', 16)->assertJsonPath('data.team_review', false)
        ->json('data.id');

    // Só o cliente aprova
    addendumAction($id, 'approve')->assertForbidden();
    apiLogin($session->client);
    addendumAction($id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');

    $fresh = $session->fresh();
    expect([$fresh?->amount, $fresh?->commission, $fresh?->provider_net])->toBe([53000, 10600, 42400]);

    addendumAction($id, 'pay', ['method' => 'multicaixa_express'])->assertOk()->assertJsonPath('data.status', 'paid');
    addendumAction($id, 'pay', ['method' => 'multicaixa_express'])->assertOk(); // repetir não cobra outra vez

    expect(EscrowHold::query()->where('service_request_id', $session->id)->sole()->only(['amount', 'commission', 'provider_net']))
        ->toBe(['amount' => 53000, 'commission' => 10600, 'provider_net' => 42400])
        ->and(balances($session)['escrow'])->toBe(53000);
});

it('acima de 20% do orçamento original vai para revisão da equipa depois de o cliente aprovar; só o admin decide', function () {
    $session = inProgressQuote('cash');
    $id = proposeAddendum($session, 12000)->assertCreated()->assertJsonPath('data.percent_of_quote', 24)->assertJsonPath('data.team_review', true)->json('data.id');

    apiLogin($session->client);
    addendumAction($id, 'approve')->assertOk()->assertJsonPath('data.status', 'pending_team');
    expect($session->fresh()?->amount)->toBe(45000);

    Filament::setCurrentPanel('backoffice');
    $addendum = QuoteAddendum::query()->findOrFail($id);

    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(ListAddenda::class)->assertOk()->assertCanSeeTableRecords([$addendum])->assertTableActionHidden('approve', $addendum);

    $admin = User::factory()->withRole(Role::Admin)->create();
    loginWeb($admin);
    Livewire::test(ListAddenda::class)
        ->callTableAction('approve', $addendum, data: ['note' => 'Peça confirmada pela fotografia.'])
        ->assertHasNoTableActionErrors();

    expect($addendum->fresh()?->status)->toBe(QuoteAddendum::APPROVED)
        ->and($addendum->fresh()?->reviewed_by)->toBe($admin->id)
        ->and($session->fresh()?->amount)->toBe(57000)
        ->and(App\Models\AuditLog::query()->where('action', 'addenda.team_approved')->exists())->toBeTrue();
});

it('conta as adendas já aprovadas para o limite', function () {
    $session = inProgressQuote('cash');
    $first = proposeAddendum($session, 8000)->json('data.id');
    apiLogin($session->client);
    addendumAction($first, 'approve')->assertOk();

    // 8.000 + 5.000 = 13.000 de 50.000 = 26% → revisão da equipa
    proposeAddendum($session, 5000)->assertCreated()->assertJsonPath('data.percent_of_quote', 26)->assertJsonPath('data.team_review', true);
});

it('o cliente recusa: o trabalho extra não é feito e o valor não muda', function () {
    $session = inProgressQuote('cash');
    $id = proposeAddendum($session, 8000)->json('data.id');

    // Uma adenda de cada vez
    proposeAddendum($session, 1000)->assertStatus(409)->assertJsonPath('error.code', 'addendum_pending');

    apiLogin($session->client);
    addendumAction($id, 'reject', ['note' => 'Não é preciso'])->assertOk()->assertJsonPath('data.status', 'rejected');
    addendumAction($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'addendum_decided');

    expect($session->fresh()?->amount)->toBe(45000)
        ->and(QuoteAddendum::query()->findOrFail($id)->client_note)->toBe('Não é preciso');
});

it('só durante a execução de um orçamento aceite', function () {
    $fixed = acceptedSession('cash');
    $fixed->forceFill(['state' => 'in_progress'])->save();
    proposeAddendum($fixed, 1000)->assertStatus(409)->assertJsonPath('error.code', 'addendum_not_allowed');

    expect(Quote::query()->count())->toBe(0);
});
