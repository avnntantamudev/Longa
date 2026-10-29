<?php

declare(strict_types=1);

use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategoryPackage;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| BISCA, Fase 2.5: hora/pacote (aulas). O técnico define o preço por hora dentro dos limites da categoria; os
| pacotes (4, 8 e 12 aulas com 5%, 10% e 15%) são configuráveis por categoria. O cliente indica o preço máximo por
| hora; o pedido só vai a técnicos dentro desse máximo e o total fixa-se com o preço de quem aceita.
*/

beforeEach(function () {
    $this->travelTo(now()->setTime(8, 0));
    Event::fake([OfferSent::class]);
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->client = User::factory()->withRole(Role::Client)->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lessonPayload(Category $category, array $overrides = []): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return [
        'category_id' => $category->id,
        'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'max_hourly_rate' => 10000,
        'duration_minutes' => 60,
        'scheduled_at' => now()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person',
        'municipality' => 'Talatona',
        'neighborhood' => 'Talatona',
        'latitude' => $lat,
        'longitude' => $lng,
        ...$overrides,
    ];
}

function providerWithRate(Category $category, int $rate, int $meters = 500): ProviderProfile
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, $meters)->withCategories($category)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $provider->id)->update(['hourly_rate' => $rate]);

    return $provider;
}

it('calcula o total: preço por hora × horas × aulas, menos o desconto', function () {
    expect(HourlyPricing::total(10000, 60))->toBe(10000)
        ->and(HourlyPricing::total(10000, 90))->toBe(15000)
        ->and(HourlyPricing::total(8000, 60, 8, 10))->toBe(57600)
        ->and(HourlyPricing::total(7777, 45, 4, 5))->toBe(22164);
});

it('o formulário mostra os pacotes da categoria (herdados de Educação) e os limites por hora', function () {
    apiLogin($this->client);

    $form = $this->getJson("/api/v1/categories/{$this->sgme->id}/form")->assertOk()->json('data');

    expect(array_map(fn ($p) => [$p['sessions'], $p['discount_percent']], $form['packages']))->toBe([[4, 5], [8, 10], [12, 15]])
        ->and($form['price']['hourly_min'])->toBe(1500)
        ->and($form['price']['hourly_max'])->toBe(20000);
});

it('o técnico define o preço por hora dentro dos limites da categoria', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    apiLogin($provider->user);

    $this->putJson('/api/v1/me/provider-profile/categories', ['category_ids' => [$this->sgme->id], 'hourly_rates' => [$this->sgme->id => 50000]])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ["hourly_rates.{$this->sgme->id}"]]]);

    $this->putJson('/api/v1/me/provider-profile/categories', ['category_ids' => [$this->sgme->id], 'hourly_rates' => [$this->sgme->id => 8000]])
        ->assertOk()->assertJsonPath("data.hourly_rates.{$this->sgme->id}", 8000);
});

it('o pedido usa o preço máximo por hora e o pacote; o valor não é enviado pelo cliente', function () {
    apiLogin($this->client);
    $package = CategoryPackage::query()->where('sessions', 8)->firstOrFail();

    $this->postJson('/api/v1/service-requests', lessonPayload($this->sgme, ['amount' => 1000]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount']]]);
    $this->postJson('/api/v1/service-requests', lessonPayload($this->sgme, ['max_hourly_rate' => 100000]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['max_hourly_rate']]]);
    $this->postJson('/api/v1/service-requests', lessonPayload($this->sgme, ['category_package_id' => 999999]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['category_package_id']]]);

    providerWithRate($this->sgme, 8000);
    $this->postJson('/api/v1/service-requests', lessonPayload($this->sgme, ['category_package_id' => $package->id]))
        ->assertCreated()
        ->assertJsonPath('data.pricing_mode', 'hourly_package')
        ->assertJsonPath('data.max_hourly_rate', 10000)
        ->assertJsonPath('data.sessions', 8)
        ->assertJsonPath('data.discount_percent', 10)
        // Estimativa ao preço máximo: 10.000 × 1 h × 8 aulas − 10%
        ->assertJsonPath('data.amount', 72000);
});

it('só oferece a técnicos dentro do máximo e o total fixa-se com o preço de quem aceita', function () {
    $cheap = providerWithRate($this->sgme, 8000, 500);
    $expensive = providerWithRate($this->sgme, 12500, 300);
    $package = CategoryPackage::query()->where('sessions', 8)->firstOrFail();
    apiLogin($this->client);

    $id = $this->postJson('/api/v1/service-requests', lessonPayload($this->sgme, ['category_package_id' => $package->id]))
        ->assertCreated()->json('data.id');

    expect(RequestOffer::query()->where('service_request_id', $id)->pluck('provider_profile_id')->all())->toBe([$cheap->id]);

    apiLogin($cheap->user);
    $offer = RequestOffer::query()->where('service_request_id', $id)->sole();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    // 8.000 × 1 h × 8 aulas − 10% = 57.600 Kz; comissão de 20% sobre o total
    $request = ServiceRequest::query()->findOrFail($id);
    expect([$request->hourly_rate, $request->amount, $request->commission, $request->provider_net])->toBe([8000, 57600, 11520, 46080]);

    apiLogin($this->client);
    $this->getJson("/api/v1/service-requests/{$id}")->assertJsonPath('data.amount', 57600)->assertJsonPath('data.hourly_rate', 8000);
    expect($expensive->id)->toBeInt();
});
