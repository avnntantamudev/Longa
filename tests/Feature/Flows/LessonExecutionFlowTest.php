<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Fluxo completo da Fase 3, só pela API:
| pedido → formador aceita → pagamento (mock) → escrow → iniciar → terminar → QR → leitura → concluída →
| carteira do formador → evento em tempo real e push. A reconciliação passa no fim.
*/

it('executa a aula de ponta a ponta com QR e liberta o dinheiro uma vez', function () {
    Cache::flush();
    Notification::fake();
    Event::fake([SessionStarted::class, QrIssued::class, SessionConfirmed::class, WalletUpdated::class]);
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, 900)->withCategories($sgme)->create();
    $client = User::factory()->withRole(Role::Client)->create();
    $key = fn (string $step) => ['Idempotency-Key' => "flow-{$step}-".uniqid()];

    // 1. Pedido e aceitação (TRIM TRIM)
    apiLogin($client);
    $requestId = $this->postJson('/api/v1/service-requests', [
        'category_id' => $sgme->id, 'title' => 'Aula de SGME', 'amount' => 25000, 'duration_minutes' => 90, 'payment_method' => 'electronic',
        'scheduled_at' => now()->addDay()->setTime(14, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');

    apiLogin($provider->user);
    $offerId = $this->getJson('/api/v1/offers')->json('data.0.id');
    $sessionId = $this->postJson("/api/v1/offers/{$offerId}/accept")->assertOk()->json('data.id');

    // 2. Pagamento dentro da janela: dinheiro retido
    apiLogin($client);
    $this->getJson("/api/v1/sessions/{$sessionId}")->assertJsonPath('data.state', 'pending_payment')->assertJsonPath('data.actions', ['pay'])
        ->assertJsonPath('data.duration_minutes', 90);
    $payKey = $key('pay');
    $this->postJson("/api/v1/sessions/{$sessionId}/pay", ['method' => 'multicaixa_express'], $payKey)->assertOk()->assertJsonPath('data.state', 'scheduled');
    $this->postJson("/api/v1/sessions/{$sessionId}/pay", ['method' => 'multicaixa_express'], $payKey)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    apiLogin($provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.pending', 21250)->assertJsonPath('data.available', 0);

    // 3. No dia: iniciar e terminar; o formador recebe o lote de QR
    $session = App\Modules\Sessions\Models\ServiceJob::query()->findOrFail($sessionId);
    $this->travelTo($session->scheduled_at->copy()->subMinutes(5));
    $this->postJson("/api/v1/sessions/{$sessionId}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');
    $this->travel(90)->minutes();
    $qr = $this->postJson("/api/v1/sessions/{$sessionId}/finish")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('qr');
    expect($qr['tokens'])->toHaveCount(10);

    // 4. O aluno lê o QR: aula concluída e dinheiro libertado
    apiLogin($client);
    $confirmKey = $key('confirm');
    $this->postJson("/api/v1/sessions/{$sessionId}/confirm", ['token' => $qr['tokens'][0]['token'], 'latitude' => $lat, 'longitude' => $lng], $confirmKey)
        ->assertOk()->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('message', 'Aula confirmada, Kz 25.000 libertados para o formador.');
    // Repetição (rede instável) devolve a mesma resposta; outro token do lote já não serve
    $this->postJson("/api/v1/sessions/{$sessionId}/confirm", ['token' => $qr['tokens'][0]['token'], 'latitude' => $lat, 'longitude' => $lng], $confirmKey)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson("/api/v1/sessions/{$sessionId}/confirm", ['token' => $qr['tokens'][1]['token']], $key('confirm2'))->assertOk()->assertJsonPath('data.state', 'completed');

    // 5. Carteira do formador
    apiLogin($provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', 21250)->assertJsonPath('data.pending', 0);
    $this->getJson('/api/v1/wallet/movements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 21250);

    // 6. Tempo real (canais privados) e push
    Event::assertDispatched(SessionStarted::class, fn ($e) => $e->session->id === $sessionId);
    Event::assertDispatched(QrIssued::class);
    Event::assertDispatched(SessionConfirmed::class, fn ($e) => $e->broadcastOn()->name === "private-session.{$sessionId}");
    Event::assertDispatched(WalletUpdated::class, fn ($e) => $e->broadcastOn()->name === "private-user.{$provider->user_id}");
    Event::assertDispatchedTimes(WalletUpdated::class, 1);

    // 7. O ledger está consistente
    expect(balances($session))->toBe(['escrow' => 0, 'provider' => 21250, 'revenue' => 3750, 'refunds' => 0, 'funds_in' => -25000]);
    $this->travelBack();
    $this->artisan('longa:ledger:reconcile')->assertSuccessful();

    expect(App\Modules\Sessions\Models\SessionEvent::query()->where('service_job_id', $sessionId)->pluck('type')->all())
        ->toContain('payment_requested', 'payment_confirmed', 'started', 'finished', 'qr_issued', 'qr_scanned', 'confirmed');
    expect(Notification::sent($client, SessionUpdateNotification::class))->toBeEmpty(); // eventos falsos: sem listeners
});
