<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestCancelled;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // TRIM TRIM do próprio dia (pedidos para outro dia têm testes próprios em ScheduledRequestsTest)
    $this->travelTo(now()->setTime(8, 0));
    Event::fake([OfferSent::class, RequestExpired::class, RequestCancelled::class]);
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->client = User::factory()->withRole(Role::Client)->create();
});

function payload(array $overrides = []): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return [
        'category_id' => test()->sgme->id,
        'title' => 'Serviço de SGME',
        'description' => 'Rever controlo e monitorização.',
        'amount' => 25000,
        'scheduled_at' => now()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person',
        'municipality' => 'Talatona',
        'neighborhood' => 'Talatona',
        'latitude' => $lat,
        'longitude' => $lng,
        ...$overrides,
    ];
}

function verifiedProviders(int $count, int $startMeters = 300): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return array_map(
        fn (int $i) => ProviderProfile::factory()->verified(50 + $i)->near($lat, $lng, $startMeters + $i * 100)->withCategories(test()->sgme)->create(),
        range(1, $count),
    );
}

it('publica o pedido, calcula a comissão e envia a 1.ª onda', function () {
    verifiedProviders(3);
    apiLogin($this->client);

    $response = $this->postJson('/api/v1/service-requests', payload())->assertCreated();

    $response->assertJsonPath('data.state', 'searching')
        ->assertJsonPath('data.wave', 1)
        ->assertJsonPath('data.open_offers', 3)
        ->assertJsonPath('data.amount', 25000);

    $request = ServiceRequest::query()->sole();
    expect($request->commission)->toBe(5000)
        ->and($request->provider_net)->toBe(20000)
        ->and((int) round(now()->diffInSeconds($request->expires_at)))->toBeBetween(295, 300);

    Event::assertDispatchedTimes(OfferSent::class, 3);
    foreach (RequestOffer::query()->get() as $offer) {
        expect((int) round(now()->diffInSeconds($offer->expires_at)))->toBeBetween(58, 60);
    }
});

it('expira logo quando não há técnicos elegíveis', function () {
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', payload())->assertCreated()->assertJsonPath('data.state', 'no_provider');

    Event::assertDispatched(RequestExpired::class, fn ($e) => $e->reason === 'no_providers');
});

it('valida valor mínimo, data futura e local em Angola', function () {
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', payload([
        'amount' => 500,
        'scheduled_at' => now()->addMinutes(5)->toIso8601String(),
        'latitude' => 38.7, 'longitude' => -9.1,
    ]))->assertStatus(422)->assertJsonValidationErrors(['amount', 'scheduled_at', 'latitude'], 'error.details');
});

it('limita os pedidos ativos por cliente', function () {
    config(['longa.requests.max_active_per_client' => 1]);
    verifiedProviders(2);
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', payload())->assertCreated();
    $this->postJson('/api/v1/service-requests', payload(['category_id' => Category::query()->where('slug', 'matematica')->value('id')]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'too_many_active_requests');
});

it('deteta pedidos duplicados', function () {
    verifiedProviders(2);
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', payload())->assertCreated();
    $this->postJson('/api/v1/service-requests', payload(['scheduled_at' => now()->setTime(15, 0)->toIso8601String()]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'duplicate_request');
});

it('limita a criação de pedidos por hora', function () {
    config(['longa.requests.create_per_hour' => 2, 'longa.requests.max_active_per_client' => 10]);
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', payload())->assertCreated();
    $this->postJson('/api/v1/service-requests', payload(['title' => 'Outra']))->assertCreated();
    $this->postJson('/api/v1/service-requests', payload(['title' => 'Mais uma']))->assertStatus(429);
});

it('só clientes publicam pedidos', function () {
    apiLogin(ProviderProfile::factory()->create()->user);

    $this->postJson('/api/v1/service-requests', payload())->assertForbidden();
});

it('cancela enquanto procura e fecha as ofertas abertas', function () {
    verifiedProviders(2);
    apiLogin($this->client);
    $id = $this->postJson('/api/v1/service-requests', payload())->json('data.id');

    $this->postJson("/api/v1/service-requests/{$id}/cancel", ['reason' => 'Já não preciso'])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect(RequestOffer::query()->pluck('state')->unique()->all())->toBe([OfferState::Lost]);
    Event::assertDispatched(RequestCancelled::class, fn ($e) => count($e->providerUserIds) === 2);

    $this->postJson("/api/v1/service-requests/{$id}/cancel")->assertStatus(409);
});

it('republica um pedido expirado como pedido novo ligado ao original', function () {
    apiLogin($this->client);
    $id = $this->postJson('/api/v1/service-requests', payload())->json('data.id'); // expira (sem técnicos)

    verifiedProviders(1);

    $this->postJson("/api/v1/service-requests/{$id}/republish", ['amount' => 30000])
        ->assertCreated()
        ->assertJsonPath('data.state', 'searching')
        ->assertJsonPath('data.amount', 30000)
        ->assertJsonPath('data.republished_from_id', $id);

    // Republicar outra vez o mesmo original seria um duplicado do pedido novo, que ainda está à procura
    $this->postJson("/api/v1/service-requests/{$id}/republish")->assertStatus(409)->assertJsonPath('error.code', 'duplicate_request');
    expect(ServiceRequest::query()->count())->toBe(2);
});

it('não deixa ver nem cancelar pedidos de outros clientes', function () {
    $other = ServiceRequest::factory()->create();
    apiLogin($this->client);

    $this->getJson("/api/v1/service-requests/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/service-requests/{$other->id}/cancel")->assertForbidden();
});
