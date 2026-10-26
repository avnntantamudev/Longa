<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;

it('o formador escreve um bairro que não está na lista e marca o ponto no mapa', function () {
    $trainer = TrainerProfile::factory()->create();
    apiLogin($trainer->user);

    $this->putJson('/api/v1/me/trainer-profile/location', [
        'municipality' => 'Cacuaco', 'neighborhood' => 'Bairro Paraíso (perto do mercado)',
        'latitude' => -8.7769, 'longitude' => 13.3708, 'service_radius_m' => 8000, 'accept_location' => true,
    ])->assertOk()
        ->assertJsonPath('data.neighborhood', 'Bairro Paraíso (perto do mercado)')
        ->assertJsonPath('data.latitude', -8.7769);
});

it('o aluno pede uma aula num bairro escrito à mão', function () {
    apiLogin(User::factory()->withRole(Role::Student)->create());

    $this->postJson('/api/v1/training-requests', [
        'skill_id' => Skill::query()->value('id'), 'title' => 'Aula de Excel', 'amount' => 10000,
        'scheduled_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Viana', 'neighborhood' => 'Zango 3, quarteirão 12', 'latitude' => -8.9201, 'longitude' => 13.4302,
    ])->assertCreated()->assertJsonPath('data.neighborhood', 'Zango 3, quarteirão 12');
});

it('continua a recusar pontos fora de Angola', function () {
    $trainer = TrainerProfile::factory()->create();
    apiLogin($trainer->user);

    $this->putJson('/api/v1/me/trainer-profile/location', [
        'municipality' => 'Lisboa', 'neighborhood' => 'Baixa', 'latitude' => 38.71, 'longitude' => -9.14,
        'service_radius_m' => 5000, 'accept_location' => true,
    ])->assertStatus(422);
});
