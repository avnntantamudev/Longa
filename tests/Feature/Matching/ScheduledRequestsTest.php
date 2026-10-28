<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function vipProvider(ProviderProfile $provider): ProviderProfile
{
    app(VipSubscriptions::class)->grant($provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');

    return $provider;
}

function publishRequest(Category $category, string $when, string $province = 'Luanda'): ServiceRequest
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    apiLogin(User::factory()->withRole(Role::Client)->create());
    $id = test()->postJson('/api/v1/service-requests', [
        'category_id' => $category->id, 'title' => 'Aula', 'amount' => 20000, 'modality' => 'in_person',
        'scheduled_at' => $when, 'province' => $province,
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');

    return ServiceRequest::query()->findOrFail($id);
}

it('pedido para outro dia: formadores a 5 km e VIP da província recebem; VIP de outra província e normais longe não', function () {
    $category = Category::query()->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $near = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $farVip = vipProvider(ProviderProfile::factory()->verified()->withCategories($category)->near($lat, $lng, 40000)->create());
    $farNormal = ProviderProfile::factory()->verified()->withCategories($category)->near($lat, $lng, 40000)->create();
    $otherProvinceVip = vipProvider(ProviderProfile::factory()->verified()->withCategories($category)->near($lat, $lng, 40000)->create(['province' => 'Benguela']));

    $request = publishRequest($category, now()->addDays(2)->setTime(10, 0)->toIso8601String());

    expect($request->scheduled)->toBeTrue()
        ->and($request->expires_at?->toDateTimeString())->toBe(now()->addDays(2)->setTime(8, 0)->toDateTimeString());
    $offered = RequestOffer::query()->where('service_request_id', $request->id)->pluck('provider_profile_id')->all();
    expect($offered)->toContain($near->id, $farVip->id)->not->toContain($farNormal->id, $otherProvinceVip->id);
    // Ofertas válidas até ao fecho do pedido; aviso sem SMS
    expect(RequestOffer::query()->where('service_request_id', $request->id)->pluck('expires_at')->unique()->count())->toBe(1);
    Notification::assertSentTo($farVip->user, NewOfferNotification::class);
});

it('pedido para hoje continua TRIM TRIM normal (5 km, 5 minutos)', function () {
    $category = Category::query()->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    ProviderProfile::factory()->verified()->withCategories($category)->create();
    $farVip = vipProvider(ProviderProfile::factory()->verified()->withCategories($category)->near($lat, $lng, 40000)->create());

    $this->travelTo(now()->setTime(9, 0));
    $request = publishRequest($category, now()->setTime(15, 0)->toIso8601String());

    expect($request->scheduled)->toBeFalse()
        ->and(RequestOffer::query()->where('provider_profile_id', $farVip->id)->exists())->toBeFalse();
});

it('o VIP longe aceita a aula; os outros perdem a oferta', function () {
    $category = Category::query()->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $near = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $farVip = vipProvider(ProviderProfile::factory()->verified()->withCategories($category)->near($lat, $lng, 30000)->create());
    $request = publishRequest($category, now()->addDays(3)->setTime(9, 0)->toIso8601String());

    apiLogin($farVip->user);
    $offer = RequestOffer::query()->where('service_request_id', $request->id)->where('provider_profile_id', $farVip->id)->sole();
    $this->getJson('/api/v1/offers')->assertJsonPath('data.0.scheduled', true);
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    expect($request->fresh()?->state)->toBe(RequestState::Matched)
        ->and(RequestOffer::query()->where('provider_profile_id', $near->id)->value('state')->value)->toBe('lost');
});

it('sem ninguém, o pedido espera; quem fica elegível depois recebe; expira no fecho sem penalizar ninguém', function () {
    $category = Category::query()->firstOrFail();
    $request = publishRequest($category, now()->addDays(2)->setTime(10, 0)->toIso8601String());
    expect($request->fresh()?->state)->toBe(RequestState::Searching);

    $late = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $this->travel(11)->minutes();
    app(MatchingCoordinator::class)->sweep();
    expect(RequestOffer::query()->where('provider_profile_id', $late->id)->exists())->toBeTrue();

    $this->travelTo($request->fresh()?->expires_at?->copy()->addMinute());
    app(MatchingCoordinator::class)->sweep();

    expect($request->fresh()?->state)->toBe(RequestState::Expired)
        ->and(ScoreEvent::query()->where('type', ScoreEvent::OFFER_EXPIRED)->count())->toBe(0);
});
