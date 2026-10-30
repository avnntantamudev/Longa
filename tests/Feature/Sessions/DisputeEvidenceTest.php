<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Services\AddendumFlow;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Sessions\Actions\SendMessage;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| BISCA, Fase 3.5: numa disputa, a equipa vê o chat (com o texto original das mensagens sinalizadas), as respostas e
| fotos do pedido, o orçamento aceite, as adendas e o histórico de estados; a decisão gera os movimentos.
*/

it('a equipa vê todas as evidências e a decisão de reembolso devolve o dinheiro retido', function () {
    Notification::fake();
    $ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
    $session = acceptedSession('electronic', ['category_id' => $ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'payment_status' => null, 'payment_due_at' => null, 'visit_fee' => 5000, 'answers' => ['descricao' => 'Pinga água na sala', 'aparelhos' => 1]])->save();
    $provider = $session->provider->user;

    $quotes = app(QuoteFlow::class);
    $quotes->startDiagnosis($session, $provider);
    $quote = $quotes->submit($session, $provider, ['labour_amount' => 30000, 'materials_amount' => 10000, 'execution_days' => 1, 'notes' => 'Limpar o dreno']);
    $quotes->accept($quote, $session->client);
    app(PayForSession::class)->handle($session->fresh() ?? $session, $session->client, 'multicaixa_express');
    app(StartSession::class)->handle($session->fresh() ?? $session, $provider);
    $addendum = app(AddendumFlow::class)->submit($session, $provider, 'Trocar a bomba de condensados', 5000);
    app(AddendumFlow::class)->clientApprove($addendum, $session->client);

    $chat = app(SendMessage::class);
    $chat->handle($session, $provider, 'Chego às 10h', null, null);
    $chat->handle($session, $provider, 'Paga-me por fora pelo 923 456 789', null, null);

    apiLogin($session->client);
    $this->post("/api/v1/sessions/{$session->id}/disputes", ['reason' => 'lesson_not_happened', 'description' => 'Continua a pingar água depois do serviço.'], ['Accept' => 'application/json', 'Idempotency-Key' => 'evidence-dispute'])->assertCreated();
    $dispute = Dispute::query()->sole();

    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
        ->assertSee('Pinga água na sala')                  // respostas do pedido
        ->assertSee('Limpar o dreno')                      // orçamento aceite
        ->assertSee('Trocar a bomba de condensados')       // adenda
        ->assertSee('Chego às 10h')                        // chat
        ->assertSee('Paga-me por fora pelo 923 456 789')   // texto original da mensagem sinalizada
        ->assertSee('Em diagnóstico')                      // histórico de estados
        ->assertSee('Em disputa');

    // Decisão do admin: reembolso total do que está retido (35.000 do orçamento; a adenda ainda não foi paga)
    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['decision' => 'refunded', 'note' => 'Serviço não resolveu a avaria.'], ['Idempotency-Key' => 'evidence-resolve'])->assertOk();

    expect($session->fresh()?->state)->toBe(RequestState::Cancelled)
        ->and(app(LedgerService::class)->balance(Accounts::ESCROW))->toBe(0)
        ->and(app(LedgerService::class)->balance(Accounts::providerAvailable($session->provider_profile_id)))->toBe(0)
        // Reembolso registado e executado no gateway (fila síncrona nos testes)
        ->and(App\Modules\Wallet\Models\LedgerTransaction::query()->where('type', 'refund')->where('reference_id', $session->id)->exists())->toBeTrue();
});
