<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Notification;

/*
| Fluxo completo da Fase 2, só pela API (como a PWA e a futura app Flutter):
| cliente publica → 3 técnicos recebem a oferta → um aceita → os outros perdem → serviço criado → chat funciona.
*/

it('cumpre o fluxo TRIM TRIM de ponta a ponta', function () {
    Notification::fake();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $providers = collect([800, 1500, 2500])->map(fn (int $m, int $i) => ProviderProfile::factory()->verified(70 - $i)->near($lat, $lng, $m)->withCategories($sgme)->create());
    $farAway = ProviderProfile::factory()->verified()->near($lat, $lng, 9000)->withCategories($sgme)->create();
    $client = User::factory()->withRole(Role::Client)->create();

    // 1. Cliente publica
    apiLogin($client);
    $requestId = $this->postJson('/api/v1/service-requests', [
        'category_id' => $sgme->id, 'title' => 'Serviço de SGME', 'amount' => 25000,
        'scheduled_at' => now()->addDay()->setTime(14, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->assertJsonPath('data.state', 'searching')->json('data.id');

    // 2. Os 3 técnicos do raio recebem a oferta (o que está a 9 km não)
    foreach ($providers as $provider) {
        Notification::assertSentTo($provider->user, NewOfferNotification::class);
        apiLogin($provider->user);
        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.request_id', $requestId);
    }
    Notification::assertNotSentTo($farAway->user, NewOfferNotification::class);

    // 3. O segundo técnico aceita; 4. os outros perdem
    $winner = $providers[1];
    apiLogin($winner->user);
    $offerId = $this->getJson('/api/v1/offers')->json('data.0.id');
    $sessionId = $this->postJson("/api/v1/offers/{$offerId}/accept")->assertOk()->json('data.id');

    foreach ([$providers[0], $providers[2]] as $loser) {
        apiLogin($loser->user);
        $this->getJson('/api/v1/offers')->assertJsonCount(0, 'data');
        $lostOffer = $loser->fresh()?->user->providerProfile?->id;
        expect(App\Modules\Requests\Models\RequestOffer::query()->where('provider_profile_id', $lostOffer)->value('state'))->toBe(OfferState::Lost);
    }

    // 5. Serviço criado: o cliente vê-a com os dados do técnico e é notificado
    apiLogin($client);
    $this->getJson("/api/v1/service-requests/{$requestId}")->assertJsonPath('data.state', 'matched')->assertJsonPath('data.session_id', $sessionId);
    $this->getJson("/api/v1/sessions/{$sessionId}")->assertOk()->assertJsonPath('data.provider.name', $winner->user->name);
    Notification::assertSentTo($client, RequestOutcomeNotification::class, fn ($n) => $n->outcome === 'matched');

    // 6. Chat funciona nos dois sentidos
    $this->postJson("/api/v1/sessions/{$sessionId}/messages", ['body' => 'Olá! Pronto para o serviço de SGME?'])->assertCreated();
    apiLogin($winner->user);
    $this->postJson("/api/v1/sessions/{$sessionId}/messages", ['body' => 'Bom dia! Sim, estou pronto.'])->assertCreated();
    $this->getJson("/api/v1/sessions/{$sessionId}/messages")->assertJsonCount(2, 'data');

    // Um perdedor não entra no serviço
    apiLogin($providers[0]->user);
    $this->getJson("/api/v1/sessions/{$sessionId}/messages")->assertForbidden();
});
