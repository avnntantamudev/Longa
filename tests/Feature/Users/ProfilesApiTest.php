<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\Consent;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Laravel\Sanctum\Sanctum;

it('conclui o registo com vários perfis e regista os consentimentos', function () {
    $user = apiLogin(User::factory()->notOnboarded()->create());

    $this->postJson('/api/v1/onboarding', [
        'name' => 'Maria Silva',
        'profiles' => ['client', 'provider'],
        'accept_terms' => true,
        'accept_privacy' => true,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Maria Silva')
        ->assertJsonPath('data.profiles.client', true)
        ->assertJsonPath('data.profiles.provider', true)
        ->assertJsonPath('data.needs_onboarding', false);

    expect($user->fresh()?->clientProfile)->not->toBeNull()
        ->and($user->fresh()?->providerProfile)->not->toBeNull()
        ->and(Consent::query()->where('user_id', $user->id)->pluck('type')->sort()->values()->all())->toBe(['privacy', 'terms']);
});

it('exige a aceitação dos termos e não permite escolher o perfil de admin', function () {
    apiLogin(User::factory()->notOnboarded()->create());

    $this->postJson('/api/v1/onboarding', ['name' => 'X', 'profiles' => ['admin']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'profiles.0', 'accept_terms', 'accept_privacy'], 'error.details');
});

it('conclui o onboarding do técnico por passos', function () {
    $user = apiLogin(User::factory()->withRole(Role::Provider)->create());
    $user->providerProfile()->create([]);
    $categories = Category::query()->limit(2)->pluck('id')->all();

    $this->putJson('/api/v1/me/provider-profile/details', [
        'bio' => 'Técnico de SGME com experiência em empresas de Talatona.',
        'years_experience' => 6,
        'modalities' => ['in_person', 'online'],
        'rate_amount' => 25000,
        'rate_unit' => 'day',
    ])->assertOk()->assertJsonPath('data.onboarding.completed', false);

    $this->putJson('/api/v1/me/provider-profile/categories', ['category_ids' => $categories])
        ->assertOk()
        ->assertJsonCount(2, 'data.categories');

    $this->putJson('/api/v1/me/provider-profile/location', [
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona',
        'latitude' => -8.9167, 'longitude' => 13.1833,
        'service_radius_m' => 5000, 'accept_location' => true,
    ])->assertOk()
        ->assertJsonPath('data.onboarding.completed', false) // falta a foto
        ->assertJsonPath('data.onboarding.photo', false);

    Illuminate\Support\Facades\Storage::fake('local');
    $this->post('/api/v1/me/photo', ['photo' => Illuminate\Http\UploadedFile::fake()->image('eu.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();

    $this->getJson('/api/v1/me/provider-profile')
        ->assertJsonPath('data.onboarding.photo', true)
        ->assertJsonPath('data.onboarding.completed', true)
        ->assertJsonPath('data.verification_status', 'unverified');

    expect(Consent::query()->where('user_id', $user->id)->where('type', 'location')->exists())->toBeTrue();
});

it('rejeita locais fora de Angola', function () {
    $user = apiLogin(User::factory()->withRole(Role::Provider)->create());
    $user->providerProfile()->create([]);

    $this->putJson('/api/v1/me/provider-profile/location', [
        'municipality' => 'Lisboa', 'neighborhood' => 'Baixa',
        'latitude' => 38.71, 'longitude' => -9.14,
        'service_radius_m' => 5000, 'accept_location' => true,
    ])->assertStatus(422)->assertJsonValidationErrors(['latitude'], 'error.details');
});

it('mostra o perfil público sem telefone nem coordenadas', function () {
    $profile = ProviderProfile::factory()->verified(87)->create();

    $response = $this->getJson("/api/v1/providers/{$profile->id}")->assertOk();

    $response->assertJsonPath('data.verified', true)->assertJsonPath('data.neighborhood', 'Talatona');
    expect($response->json('data'))->not->toHaveKeys(['phone', 'latitude', 'longitude', 'location', 'user_id']);
    expect($response->getContent())->not->toContain($profile->user->phone);
});

it('esconde perfis de técnicos não verificados', function () {
    $profile = ProviderProfile::factory()->create();

    $this->getJson("/api/v1/providers/{$profile->id}")->assertNotFound();
});

it('lista áreas de serviço e zonas sem autenticação', function () {
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonFragment(['name' => 'SGME — Gestão Empresarial']);
    $this->getJson('/api/v1/zones')->assertOk()->assertJsonFragment(['neighborhood' => 'Talatona']);
});

it('exige autenticação nas rotas de perfil', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    // Token sem AuthSession associada (ex.: criado fora do fluxo de login) não é aceite
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
