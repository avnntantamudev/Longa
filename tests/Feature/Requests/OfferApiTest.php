<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([RequestMatched::class]);
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $this->providers = array_map(
        fn (int $i) => ProviderProfile::factory()->verified(60 + $i)->near($lat, $lng, 300 * $i)->withCategories($sgme)->create(),
        [1, 2, 3],
    );
    $this->request = ServiceRequest::factory()->forCategory($sgme)->create();
    app(MatchingCoordinator::class)->start($this->request);
});

function offerOf(ProviderProfile $provider): RequestOffer
{
    return RequestOffer::query()->where('provider_profile_id', $provider->id)->firstOrFail();
}

it('mostra ao técnico só os dados permitidos antes de aceitar', function () {
    apiLogin($this->providers[0]->user);

    $response = $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1, 'data');
    $offer = $response->json('data.0');

    expect($offer)->toHaveKeys(['title', 'amount', 'provider_net', 'neighborhood', 'distance_m', 'seconds_left'])
        ->not->toHaveKeys(['latitude', 'longitude', 'client', 'phone'])
        ->and($offer['distance_m'] % 100)->toBe(0)
        ->and($offer['provider_net'])->toBe(20000);
    expect($response->getContent())->not->toContain($this->request->client->phone);
});

it('aceita: cria o serviço, as outras ofertas perdem e o pedido fica matched', function () {
    apiLogin($this->providers[1]->user);
    $offer = offerOf($this->providers[1]);

    $this->postJson("/api/v1/offers/{$offer->id}/view")->assertOk()->assertJsonPath('data.state', 'viewed');

    $response = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    $response->assertJsonPath('data.state', 'accepted')
        ->assertJsonPath('data.role', 'provider')
        ->assertJsonPath('data.provider_net', 20000)
        ->assertJsonPath('data.latitude', ProviderProfileFactory::TALATONA[0]);

    $session = ServiceRequest::query()->sole();
    expect($session->payment_due_at)->not->toBeNull()
        ->and($session->conversation)->not->toBeNull()
        ->and($this->request->fresh()?->state)->toBe(RequestState::Accepted)
        ->and($this->request->fresh()?->provider_profile_id)->toBe($this->providers[1]->id)
        ->and(RequestOffer::query()->where('state', 'lost')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'requests.offer_accepted')->exists())->toBeTrue();

    Event::assertDispatched(RequestMatched::class, fn ($e) => count($e->loserUserIds) === 2);
});

it('é idempotente para o técnico que ganhou', function () {
    apiLogin($this->providers[0]->user);
    $offer = offerOf($this->providers[0]);

    $first = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');
    $second = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');

    expect($second)->toBe($first)->and(ServiceRequest::query()->count())->toBe(1);
});

it('responde ao perdedor que outro técnico aceitou primeiro', function () {
    apiLogin($this->providers[0]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->providers[0])->id.'/accept')->assertOk();

    apiLogin($this->providers[1]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->providers[1])->id.'/accept')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_already_matched')
        ->assertJsonPath('error.message', 'Outro técnico aceitou este pedido primeiro.');
});

it('impõe o fim da procura no servidor, mesmo antes do job de expiração correr', function () {
    apiLogin($this->providers[0]->user);
    $offer = offerOf($this->providers[0]);

    $this->travel(61)->minutes();
    apiLogin($this->providers[0]->user);

    $this->postJson("/api/v1/offers/{$offer->id}/accept")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'request_closed');

    expect(ServiceRequest::query()->whereNotNull('provider_profile_id')->count())->toBe(0)
        ->and($offer->fresh()?->state)->toBe(OfferState::Sent);
});

it('recusar regista um evento de score e impede aceitar depois', function () {
    apiLogin($this->providers[0]->user);
    $offer = offerOf($this->providers[0]);

    $this->postJson("/api/v1/offers/{$offer->id}/decline")->assertOk()->assertJsonPath('data.state', 'declined');
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'offer_declined');

    expect(ScoreEvent::query()->where('type', ScoreEvent::OFFER_DECLINED)->where('provider_profile_id', $this->providers[0]->id)->exists())->toBeTrue();
});

it('não aceita ofertas de pedidos cancelados', function () {
    apiLogin($this->request->client);
    $this->postJson("/api/v1/service-requests/{$this->request->id}/cancel")->assertOk();

    apiLogin($this->providers[0]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->providers[0])->id.'/accept')->assertStatus(410);
});

it('um técnico não vê nem responde às ofertas de outro', function () {
    apiLogin($this->providers[0]->user);
    $foreign = offerOf($this->providers[1]);

    $this->getJson("/api/v1/offers/{$foreign->id}")->assertForbidden();
    $this->postJson("/api/v1/offers/{$foreign->id}/accept")->assertForbidden();
});

it('clientes não acedem às ofertas', function () {
    apiLogin($this->request->client);

    $this->getJson('/api/v1/offers')->assertForbidden();
});

it('técnico só fica disponível se estiver verificado e completo', function () {
    $unverified = ProviderProfile::factory()->create(['is_available' => false]);
    apiLogin($unverified->user);

    $this->putJson('/api/v1/me/provider-profile/availability', ['is_available' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'provider_not_ready');

    apiLogin($this->providers[0]->user);
    $this->putJson('/api/v1/me/provider-profile/availability', ['is_available' => false])
        ->assertOk()->assertJsonPath('data.is_available', false);
});
