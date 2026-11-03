<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Notifications\Notifications\RebookInterestNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| "Pedir ao mesmo técnico": só técnicos VIP que já fizeram um serviço pago para o cliente. O pedido vai só para
| esse técnico (30 min); se recusar ou não responder, o cliente pode enviar a todos. Para técnicos sem VIP o
| cliente só regista o interesse, e o técnico fica a saber quantos clientes o queriam chamar.
*/

beforeEach(function () {
    $this->travelTo(now()->setTime(8, 0));
    Event::fake([OfferSent::class]);
    Notification::fake();
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->client = User::factory()->withRole(Role::Client)->create();
    $this->provider = directTestProvider($this->sgme);
    $this->other = directTestProvider($this->sgme, 300); // outro técnico elegível, mais perto
});

function directTestProvider(Category $category, int $meters = 800): ProviderProfile
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, $meters)->withCategories($category)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $provider->id)->update(['hourly_rate' => 5000]);

    return $provider;
}

function directTestVip(ProviderProfile $provider): void
{
    app(VipSubscriptions::class)->grant($provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
}

/** Serviço pago entre o cliente e o técnico (o que dá direito a pedir de novo). */
function directTestPastService(User $client, ProviderProfile $provider, Category $category): ServiceRequest
{
    $past = ServiceRequest::factory()->create(['client_id' => $client->id, 'category_id' => $category->id, 'scheduled_at' => now()->subWeek()]);
    $past->forceFill(['state' => RequestState::Paid->value, 'provider_profile_id' => $provider->id])->save();

    return $past;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function directTestPayload(Category $category, array $overrides = []): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return [
        'category_id' => $category->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'max_hourly_rate' => 10000, 'duration_minutes' => 60, 'scheduled_at' => now()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
        ...$overrides,
    ];
}

it('o pedido direto vai só para o técnico VIP escolhido, durante 30 minutos, sem rondas', function () {
    directTestVip($this->provider);
    directTestPastService($this->client, $this->provider, $this->sgme);
    apiLogin($this->client);

    $id = $this->postJson('/api/v1/service-requests', directTestPayload($this->sgme, ['direct_provider_id' => $this->provider->id]))
        ->assertCreated()->assertJsonPath('data.direct_provider.id', $this->provider->id)->json('data.id');

    $offers = RequestOffer::query()->where('service_request_id', $id)->get();
    expect($offers)->toHaveCount(1)
        ->and($offers->first()->provider_profile_id)->toBe($this->provider->id)
        ->and((int) round(now()->diffInMinutes($offers->first()->expires_at)))->toBe(30);
    Event::assertDispatchedTimes(OfferSent::class, 1);

    // Passam as rondas normais: continua a não ir para mais ninguém
    $this->travel(15)->minutes();
    app(MatchingCoordinator::class)->sweep();
    expect(RequestOffer::query()->where('service_request_id', $id)->count())->toBe(1);

    // O técnico aceita: fica com o serviço e paga a comissão VIP
    apiLogin($this->provider->user);
    $this->postJson("/api/v1/offers/{$offers->first()->id}/accept")->assertOk();
    expect(ServiceRequest::query()->find($id))
        ->state->toBe(RequestState::Accepted)
        ->provider_profile_id->toBe($this->provider->id)
        ->commission_percent->toBe(10);
});

it('se o técnico recusar, o pedido fecha logo e a nova tentativa envia a todos', function () {
    directTestVip($this->provider);
    directTestPastService($this->client, $this->provider, $this->sgme);
    apiLogin($this->client);
    $id = $this->postJson('/api/v1/service-requests', directTestPayload($this->sgme, ['direct_provider_id' => $this->provider->id]))->json('data.id');
    $offer = RequestOffer::query()->where('service_request_id', $id)->firstOrFail();

    apiLogin($this->provider->user);
    $this->postJson("/api/v1/offers/{$offer->id}/decline")->assertOk();

    $request = ServiceRequest::query()->findOrFail($id);
    expect($request->state)->toBe(RequestState::NoProvider)
        ->and($request->transitions()->where('to_state', RequestState::NoProvider->value)->value('reason'))->toBe('direct_declined');
    Notification::assertSentTo($this->client, RequestOutcomeNotification::class, fn ($n) => str_contains($n->toArray($this->client)['body'], 'enviar o pedido a todos'));

    apiLogin($this->client);
    $this->postJson("/api/v1/service-requests/{$id}/retry")->assertOk()->assertJsonPath('data.direct_provider', null);
    expect(RequestOffer::query()->where('service_request_id', $id)->where('provider_profile_id', $this->other->id)->exists())->toBeTrue()
        ->and($offer->fresh()?->state)->toBe(OfferState::Declined);
});

it('sem resposta em 30 minutos o pedido fica sem técnico', function () {
    directTestVip($this->provider);
    directTestPastService($this->client, $this->provider, $this->sgme);
    apiLogin($this->client);
    $id = $this->postJson('/api/v1/service-requests', directTestPayload($this->sgme, ['direct_provider_id' => $this->provider->id]))->json('data.id');

    $this->travel(31)->minutes();
    app(MatchingCoordinator::class)->sweep();

    $request = ServiceRequest::query()->findOrFail($id);
    expect($request->state)->toBe(RequestState::NoProvider)
        ->and($request->transitions()->where('to_state', RequestState::NoProvider->value)->value('reason'))->toBe('direct_timeout');
});

it('só pode pedir diretamente a um técnico VIP que já o serviu e que pode fazer o pedido', function () {
    apiLogin($this->client);
    $payload = directTestPayload($this->sgme, ['direct_provider_id' => $this->provider->id]);

    // Nunca o serviu
    $this->postJson('/api/v1/service-requests', $payload)->assertForbidden()->assertJsonPath('error.code', 'direct_not_allowed');

    // Serviu, mas não é VIP
    directTestPastService($this->client, $this->provider, $this->sgme);
    $this->postJson('/api/v1/service-requests', $payload)->assertStatus(409)->assertJsonPath('error.code', 'direct_not_vip');

    // VIP, mas já tem outro serviço a essa hora
    directTestVip($this->provider);
    $busy = ServiceRequest::factory()->create(['category_id' => $this->sgme->id, 'scheduled_at' => now()->setTime(14, 0), 'duration_minutes' => 60]);
    $busy->forceFill(['state' => RequestState::Accepted->value, 'provider_profile_id' => $this->provider->id])->save();
    $this->postJson('/api/v1/service-requests', $payload)->assertStatus(409)->assertJsonPath('error.code', 'direct_provider_unavailable');

    expect(ServiceRequest::query()->where('client_id', $this->client->id)->whereNotNull('direct_provider_id')->exists())->toBeFalse();
});

it('o serviço pago mostra "pedir de novo" para técnicos VIP e "quero voltar a chamar" para os outros', function () {
    $past = directTestPastService($this->client, $this->provider, $this->sgme);
    apiLogin($this->client);

    $this->getJson("/api/v1/sessions/{$past->id}")->assertOk()
        ->assertJsonPath('data.rebook.mode', 'interest')->assertJsonPath('data.rebook.interest_sent', false);

    directTestVip($this->provider);
    $this->getJson("/api/v1/sessions/{$past->id}")->assertJsonPath('data.rebook.mode', 'direct')
        ->assertJsonPath('data.rebook.category_id', $this->sgme->id);

    // O técnico não vê o botão
    apiLogin($this->provider->user);
    $this->getJson("/api/v1/sessions/{$past->id}")->assertJsonPath('data.rebook', null);
});

it('o interesse num técnico sem VIP fica registado uma vez e o técnico é avisado', function () {
    $past = directTestPastService($this->client, $this->provider, $this->sgme);
    apiLogin($this->client);

    $this->postJson("/api/v1/service-requests/{$past->id}/rebook-interest")->assertOk()->assertJsonPath('data.interest_sent', true);
    $this->postJson("/api/v1/service-requests/{$past->id}/rebook-interest")->assertOk();
    Notification::assertSentToTimes($this->provider->user, RebookInterestNotification::class, 1);
    $this->getJson("/api/v1/sessions/{$past->id}")->assertJsonPath('data.rebook.interest_sent', true);

    // Outro cliente: o técnico vê o total na página VIP
    $second = User::factory()->withRole(Role::Client)->create();
    $other = directTestPastService($second, $this->provider, $this->sgme);
    apiLogin($second);
    $this->postJson("/api/v1/service-requests/{$other->id}/rebook-interest")->assertOk();
    Notification::assertSentTo($this->provider->user, RebookInterestNotification::class, fn ($n) => $n->total === 2);

    apiLogin($this->provider->user);
    $this->getJson('/api/v1/me/vip')->assertJsonPath('data.rebook_interests', 2);

    // Técnico VIP: usa-se o pedido direto, não o interesse
    directTestVip($this->provider);
    apiLogin($this->client);
    $this->postJson("/api/v1/service-requests/{$past->id}/rebook-interest")->assertStatus(409)->assertJsonPath('error.code', 'rebook_use_direct');
});

it('o formulário vem preenchido com o técnico só quando o cliente lhe pode pedir diretamente', function () {
    loginWeb($this->client);
    $url = "/pedidos/novo?tecnico={$this->provider->id}&categoria={$this->sgme->id}";

    // Ainda não o serviu: formulário normal
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Requests/Create')->where('directProvider', null));

    directTestPastService($this->client, $this->provider, $this->sgme);
    directTestVip($this->provider);
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('directProvider.id', $this->provider->id)
        ->where('directProvider.name', $this->provider->user->name)
        ->where('initialCategoryId', $this->sgme->id));
});
