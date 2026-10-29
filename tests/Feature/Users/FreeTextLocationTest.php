<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;

it('o técnico escreve um bairro que não está na lista e marca o ponto no mapa', function () {
    $provider = ProviderProfile::factory()->create();
    apiLogin($provider->user);

    $this->putJson('/api/v1/me/provider-profile/location', [
        'municipality' => 'Cacuaco', 'neighborhood' => 'Bairro Paraíso (perto do mercado)',
        'latitude' => -8.7769, 'longitude' => 13.3708, 'service_radius_m' => 8000, 'accept_location' => true,
    ])->assertOk()
        ->assertJsonPath('data.neighborhood', 'Bairro Paraíso (perto do mercado)')
        ->assertJsonPath('data.latitude', -8.7769);
});

it('o cliente pede um serviço num bairro escrito à mão', function () {
    apiLogin(User::factory()->withRole(Role::Client)->create());

    $this->postJson('/api/v1/service-requests', [
        'category_id' => Category::query()->value('id'), 'title' => 'Serviço de Excel', 'amount' => 10000, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'scheduled_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Viana', 'neighborhood' => 'Zango 3, quarteirão 12', 'latitude' => -8.9201, 'longitude' => 13.4302,
    ])->assertCreated()->assertJsonPath('data.neighborhood', 'Zango 3, quarteirão 12');
});

it('continua a recusar pontos fora de Angola', function () {
    $provider = ProviderProfile::factory()->create();
    apiLogin($provider->user);

    $this->putJson('/api/v1/me/provider-profile/location', [
        'municipality' => 'Lisboa', 'neighborhood' => 'Baixa', 'latitude' => 38.71, 'longitude' => -9.14,
        'service_radius_m' => 5000, 'accept_location' => true,
    ])->assertStatus(422);
});

it('guarda a província e recusa províncias inexistentes', function () {
    $provider = ProviderProfile::factory()->create();
    apiLogin($provider->user);
    $body = ['municipality' => 'Lobito', 'neighborhood' => 'Compão', 'latitude' => -12.3644, 'longitude' => 13.5367, 'service_radius_m' => 5000, 'accept_location' => true];

    $this->putJson('/api/v1/me/provider-profile/location', [...$body, 'province' => 'Benguela'])->assertOk()->assertJsonPath('data.province', 'Benguela');
    $this->putJson('/api/v1/me/provider-profile/location', [...$body, 'province' => 'Lisboa'])->assertStatus(422);
    $this->getJson('/api/v1/provinces')->assertOk()->assertJsonCount(21, 'data');
});

it('o serviço guarda a província do pedido', function () {
    $session = acceptedSession('electronic');

    expect($session->province)->toBe('Luanda');
});
