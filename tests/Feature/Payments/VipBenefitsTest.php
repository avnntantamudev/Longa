<?php

declare(strict_types=1);

use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function makeVip(ProviderProfile $provider): void
{
    app(VipSubscriptions::class)->grant($provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
}

/**
 * Publica um pedido pela API e devolve a oferta do técnico indicado.
 */
function vipOfferFor(ProviderProfile $provider, Category $category, int $amount = 25000): RequestOffer
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    apiLogin(User::factory()->withRole(Role::Client)->create());
    test()->postJson('/api/v1/service-requests', [
        'category_id' => $category->id, 'title' => 'Serviço', 'amount' => $amount, 'modality' => 'in_person',
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated();

    return RequestOffer::query()->where('provider_profile_id', $provider->id)->latest('id')->firstOrFail();
}

it('técnico VIP aceita com comissão de 10%: serviço, oferta e escrow coerentes', function () {
    $category = Category::query()->firstOrFail();
    $provider = ProviderProfile::factory()->verified()->withCategories($category)->create();
    makeVip($provider);
    $offer = vipOfferFor($provider, $category);

    apiLogin($provider->user);
    $this->getJson('/api/v1/offers')->assertJsonPath('data.0.commission_percent', 10)->assertJsonPath('data.0.provider_net', 22500);
    $sessionId = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');

    $session = ServiceRequest::query()->findOrFail($sessionId);
    expect([$session->commission, $session->provider_net, $session->commission_percent])->toBe([2500, 22500, 10]);

    app(PayForSession::class)->handle($session, $session->client, 'multicaixa_express');
    expect(EscrowHold::query()->sole()->only(['commission', 'provider_net', 'commission_percent']))
        ->toBe(['commission' => 2500, 'provider_net' => 22500, 'commission_percent' => 10]);
});

it('técnico normal continua com 15%; a comissão fica fixa mesmo que o VIP comece depois', function () {
    $category = Category::query()->firstOrFail();
    $provider = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $offer = vipOfferFor($provider, $category);

    apiLogin($provider->user);
    $sessionId = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');
    makeVip($provider);

    expect(ServiceRequest::query()->findOrFail($sessionId)->only(['commission', 'commission_percent']))->toBe(['commission' => 5000, 'commission_percent' => 20]);
});

it('na 1.ª onda os VIP vêm primeiro, mesmo com pontuação mais baixa', function () {
    $category = Category::query()->firstOrFail();
    $top = ProviderProfile::factory()->verified(95)->withCategories($category)->create();
    $vip = ProviderProfile::factory()->verified(40)->withCategories($category)->near(...[...ProviderProfileFactory::TALATONA, 3000])->create();
    makeVip($vip);
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $request = ServiceRequest::factory()->create(['category_id' => $category->id, 'latitude' => $lat, 'longitude' => $lng, 'scheduled_at' => now()->addDay()]);

    $ids = array_map(fn ($c) => $c->providerProfileId, app(ProviderMatcher::class)->candidates($request, 10));

    expect(array_slice($ids, 0, 2))->toBe([$vip->id, $top->id]);
});

it('o selo VIP aparece ao cliente no serviço', function () {
    $session = paidSession();
    makeVip($session->provider);

    apiLogin($session->client);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.provider.vip', true);
});
