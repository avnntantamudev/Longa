<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;

beforeEach(function () {
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $this->provider = ProviderProfile::factory()->verified()->near($lat, $lng, 500)->withCategories($this->sgme)->create();
});

function newOfferFor(ProviderProfile $provider, Category $category): RequestOffer
{
    $request = ServiceRequest::factory()->forCategory($category)->create();
    app(MatchingCoordinator::class)->start($request);

    return RequestOffer::query()->where('service_request_id', $request->id)->where('provider_profile_id', $provider->id)->firstOrFail();
}

it('regista recusas repetidas e ofertas sem resposta e mostra o resumo ao técnico', function () {
    apiLogin($this->provider->user);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/offers/'.newOfferFor($this->provider, $this->sgme)->id.'/decline')->assertOk();
    }
    newOfferFor($this->provider, $this->sgme);
    // A oferta fica aberta até ao fim da procura (1 h); sem resposta, expira e conta para o score
    $this->travel(61)->minutes();
    $this->artisan('longa:matching-tick');

    apiLogin($this->provider->user);
    $this->getJson('/api/v1/me/score-events')
        ->assertOk()
        ->assertJsonPath('summary_30_days.offer_declined', 3)
        ->assertJsonPath('summary_30_days.offer_expired', 1)
        ->assertJsonCount(4, 'data');

    expect(ScoreEvent::query()->sum('points'))->toBe(0);
});

it('o admin vê os eventos de um técnico; outros não', function () {
    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->getJson("/api/v1/admin/providers/{$this->provider->id}/score-events")->assertOk();

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson("/api/v1/admin/providers/{$this->provider->id}/score-events")->assertForbidden();
});

it('um técnico suspenso deixa de receber pedidos e não consegue aceitar ofertas já recebidas', function () {
    apiLogin($this->provider->user);
    $offer = newOfferFor($this->provider, $this->sgme);

    $this->provider->user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->app['auth']->forgetGuards();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertUnauthorized();

    $next = ServiceRequest::factory()->forCategory($this->sgme)->create();
    app(MatchingCoordinator::class)->start($next);
    expect(RequestOffer::query()->where('service_request_id', $next->id)->count())->toBe(0);
});
