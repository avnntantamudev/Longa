<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferExpired;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| BISCA, Fase 2.3: despacho presencial em rondas de raio crescente (3 km, +3 km por ronda, até 15 km), dentro de
| 1 h de procura (5 rondas de 12 min). As ofertas ficam abertas até alguém aceitar ou a procura acabar.
*/

beforeEach(function () {
    Event::fake([OfferSent::class, OfferExpired::class, RequestExpired::class]);
    $this->travelTo(now()->setTime(8, 0));
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    // Técnicos a 1, 4, 7, 10 e 13 km (dentro do raio máximo) e a 17 km (fora)
    $this->providers = collect([1000, 4000, 7000, 10000, 13000, 17000])->map(fn (int $m) => ProviderProfile::factory()
        ->verified()->near($lat, $lng, $m)->withCategories($this->sgme)->create(['service_radius_m' => 20000]));
    $this->request = ServiceRequest::factory()->forCategory($this->sgme)->create(['scheduled_at' => now()->addHours(3)]);
    $this->coordinator = app(MatchingCoordinator::class);
});

/**
 * @return list<int>
 */
function offeredIds(ServiceRequest $request): array
{
    return $request->offers()->orderBy('provider_profile_id')->pluck('provider_profile_id')->all();
}

function tickAfter(int $minutes): void
{
    test()->travel($minutes)->minutes();
    test()->artisan('longa:matching-tick')->assertSuccessful();
}

it('reparte a hora de procura por 5 rondas de 12 minutos (3 → 15 km de 3 em 3 km)', function () {
    expect($this->coordinator->rounds())->toBe(5)
        ->and($this->coordinator->roundSeconds())->toBe(720)
        ->and(array_map(fn (int $w) => $this->coordinator->radiusFor($w), [1, 2, 3, 4, 5, 6]))->toBe([3000, 6000, 9000, 12000, 15000, 15000]);
});

it('alarga o raio a cada ronda e mantém abertas as ofertas já enviadas', function () {
    $ids = $this->providers->pluck('id')->all();
    $this->coordinator->start($this->request);
    expect(offeredIds($this->request))->toBe([$ids[0]]);

    // A meio da ronda nada muda
    tickAfter(6);
    expect(offeredIds($this->request))->toBe([$ids[0]]);

    tickAfter(6); // ronda 2: 6 km
    expect(offeredIds($this->request))->toBe([$ids[0], $ids[1]]);

    foreach (range(3, 5) as $round) {
        tickAfter(12);
    }

    // Até 15 km: os 5 técnicos dentro do raio máximo, nunca o de 17 km; todas as ofertas continuam abertas
    expect(offeredIds($this->request))->toBe(array_slice($ids, 0, 5))
        ->and($this->request->offers()->pluck('state')->unique()->all())->toBe([OfferState::Sent])
        ->and($this->request->fresh()?->wave)->toBe(5);
    Event::assertDispatchedTimes(OfferSent::class, 5);
});

it('a oferta diz a distância e fica aberta até ao fim da procura', function () {
    $this->coordinator->start($this->request);
    $offer = RequestOffer::query()->where('service_request_id', $this->request->id)->sole();

    expect($offer->distance_m)->toBeBetween(950, 1050)
        ->and($offer->expires_at->equalTo($this->request->fresh()?->expires_at))->toBeTrue()
        ->and((int) round(now()->diffInMinutes($offer->expires_at)))->toBe(60);
});

it('ao fim de 1 h sem ninguém aceitar o pedido fica "sem técnico disponível"', function () {
    $this->coordinator->start($this->request);

    tickAfter(59);
    expect($this->request->fresh()?->state)->toBe(RequestState::Searching);

    tickAfter(2);
    expect($this->request->fresh()?->state)->toBe(RequestState::NoProvider)
        ->and($this->request->offers()->whereIn('state', OfferState::open())->count())->toBe(0);
    Event::assertDispatched(RequestExpired::class, fn ($e) => $e->reason === 'timeout');
});

it('sem nenhum técnico até ao raio máximo, não faz o cliente esperar', function () {
    ProviderProfile::query()->whereIn('id', $this->providers->take(5)->pluck('id'))->update(['is_available' => false]);

    $this->coordinator->start($this->request);

    expect($this->request->fresh()?->state)->toBe(RequestState::NoProvider);
    Event::assertDispatched(RequestExpired::class, fn ($e) => $e->reason === 'no_providers');
});

it('é idempotente: avançar duas vezes na mesma ronda não duplica ofertas', function () {
    $this->coordinator->start($this->request);
    $this->travel(12)->minutes();

    $this->coordinator->advance($this->request);
    $this->coordinator->advance($this->request->fresh() ?? $this->request);
    $this->artisan('longa:matching-tick');

    expect($this->request->offers()->count())->toBe(2)
        ->and($this->request->fresh()?->wave)->toBe(2);
});

it('os raios e o tempo são parâmetros do painel', function () {
    DB::table('platform_settings')->insert([
        ['key' => 'dispatch_initial_radius_m', 'value' => '5000', 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'dispatch_search_minutes', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
    ]);
    cache()->forget('platform_settings');

    $this->coordinator->start($this->request);

    expect(offeredIds($this->request))->toBe($this->providers->take(2)->pluck('id')->all())
        ->and((int) round(now()->diffInMinutes($this->request->fresh()?->expires_at)))->toBe(30);
});

it('com saldo mínimo ligado, só recebe pedidos quem tem saldo suficiente', function () {
    DB::table('platform_settings')->insert(['key' => 'dispatch_min_balance', 'value' => '1000', 'created_at' => now(), 'updated_at' => now()]);
    cache()->forget('platform_settings');

    $this->coordinator->start($this->request);

    // Ninguém tem saldo: o pedido fica sem técnico disponível
    expect($this->request->offers()->count())->toBe(0)
        ->and($this->request->fresh()?->state)->toBe(RequestState::NoProvider);
});

it('nova tentativa: volta a procurar com o mesmo pedido e a oferecer a quem não respondeu', function () {
    $client = $this->request->client;
    $this->coordinator->start($this->request);
    tickAfter(12);
    $declined = RequestOffer::query()->where('service_request_id', $this->request->id)->orderBy('id')->get()[1];
    $declined->transitionTo(OfferState::Declined, ['responded_at' => now()]);
    tickAfter(49);
    expect($this->request->fresh()?->state)->toBe(RequestState::NoProvider);

    apiLogin($client);
    $this->postJson("/api/v1/service-requests/{$this->request->id}/retry")
        ->assertOk()->assertJsonPath('data.state', 'searching');

    $offers = $this->request->offers()->orderBy('id')->get();
    expect($offers[0]->state)->toBe(OfferState::Sent)
        ->and($offers[1]->state)->toBe(OfferState::Declined)
        ->and($this->request->transitions()->get()->last()?->only(['from_state', 'to_state', 'actor_id']))
        ->toBe(['from_state' => RequestState::NoProvider, 'to_state' => RequestState::Searching, 'actor_id' => $client->id]);

    // Só se tenta de novo um pedido sem técnico disponível
    $this->postJson("/api/v1/service-requests/{$this->request->id}/retry")->assertStatus(409)->assertJsonPath('error.code', 'request_not_retryable');
});

it('online: sem perímetro, o pedido é oferecido aos técnicos da categoria', function () {
    $this->providers->each(fn (ProviderProfile $p) => $p->update(['modalities' => ['in_person', 'online']]));
    $request = ServiceRequest::factory()->forCategory($this->sgme)->create(['modality' => 'online', 'scheduled_at' => now()->addHours(3)]);

    $this->coordinator->start($request);

    expect($request->offers()->count())->toBe(6);
});
