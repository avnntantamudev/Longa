<?php

declare(strict_types=1);

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestCancelled;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([OfferSent::class, RequestExpired::class, RequestCancelled::class]);
    $this->sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->student = User::factory()->withRole(Role::Student)->create();
});

function payload(array $overrides = []): array
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;

    return [
        'skill_id' => test()->sgme->id,
        'title' => 'Aula de SGME',
        'description' => 'Rever controlo e monitorização.',
        'amount' => 25000,
        'scheduled_at' => now()->addDay()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person',
        'municipality' => 'Talatona',
        'neighborhood' => 'Talatona',
        'latitude' => $lat,
        'longitude' => $lng,
        ...$overrides,
    ];
}

function verifiedTrainers(int $count, int $startMeters = 300): array
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;

    return array_map(
        fn (int $i) => TrainerProfile::factory()->verified(50 + $i)->near($lat, $lng, $startMeters + $i * 100)->withSkills(test()->sgme)->create(),
        range(1, $count),
    );
}

it('publica o pedido, calcula a comissão e envia a 1.ª onda', function () {
    verifiedTrainers(3);
    apiLogin($this->student);

    $response = $this->postJson('/api/v1/training-requests', payload())->assertCreated();

    $response->assertJsonPath('data.state', 'searching')
        ->assertJsonPath('data.wave', 1)
        ->assertJsonPath('data.open_offers', 3)
        ->assertJsonPath('data.amount', 25000);

    $request = TrainingRequest::query()->sole();
    expect($request->commission)->toBe(3750)
        ->and($request->trainer_net)->toBe(21250)
        ->and((int) round(now()->diffInSeconds($request->expires_at)))->toBeBetween(295, 300);

    Event::assertDispatchedTimes(OfferSent::class, 3);
    foreach (RequestOffer::query()->get() as $offer) {
        expect((int) round(now()->diffInSeconds($offer->expires_at)))->toBeBetween(58, 60);
    }
});

it('expira logo quando não há formadores elegíveis', function () {
    apiLogin($this->student);

    $this->postJson('/api/v1/training-requests', payload())->assertCreated()->assertJsonPath('data.state', 'expired');

    Event::assertDispatched(RequestExpired::class, fn ($e) => $e->reason === 'no_trainers');
});

it('valida valor mínimo, data futura e local em Angola', function () {
    apiLogin($this->student);

    $this->postJson('/api/v1/training-requests', payload([
        'amount' => 500,
        'scheduled_at' => now()->addMinutes(5)->toIso8601String(),
        'latitude' => 38.7, 'longitude' => -9.1,
    ]))->assertStatus(422)->assertJsonValidationErrors(['amount', 'scheduled_at', 'latitude'], 'error.details');
});

it('limita os pedidos ativos por aluno', function () {
    config(['longa.requests.max_active_per_student' => 1]);
    verifiedTrainers(2);
    apiLogin($this->student);

    $this->postJson('/api/v1/training-requests', payload())->assertCreated();
    $this->postJson('/api/v1/training-requests', payload(['skill_id' => Skill::query()->where('slug', 'matematica')->value('id')]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'too_many_active_requests');
});

it('deteta pedidos duplicados', function () {
    verifiedTrainers(2);
    apiLogin($this->student);

    $this->postJson('/api/v1/training-requests', payload())->assertCreated();
    $this->postJson('/api/v1/training-requests', payload(['scheduled_at' => now()->addDay()->setTime(15, 0)->toIso8601String()]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'duplicate_request');
});

it('limita a criação de pedidos por hora', function () {
    config(['longa.requests.create_per_hour' => 2, 'longa.requests.max_active_per_student' => 10]);
    apiLogin($this->student);

    $this->postJson('/api/v1/training-requests', payload())->assertCreated();
    $this->postJson('/api/v1/training-requests', payload(['title' => 'Outra']))->assertCreated();
    $this->postJson('/api/v1/training-requests', payload(['title' => 'Mais uma']))->assertStatus(429);
});

it('só alunos publicam pedidos', function () {
    apiLogin(TrainerProfile::factory()->create()->user);

    $this->postJson('/api/v1/training-requests', payload())->assertForbidden();
});

it('cancela enquanto procura e fecha as ofertas abertas', function () {
    verifiedTrainers(2);
    apiLogin($this->student);
    $id = $this->postJson('/api/v1/training-requests', payload())->json('data.id');

    $this->postJson("/api/v1/training-requests/{$id}/cancel", ['reason' => 'Já não preciso'])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect(RequestOffer::query()->pluck('state')->unique()->all())->toBe([OfferState::Lost]);
    Event::assertDispatched(RequestCancelled::class, fn ($e) => count($e->trainerUserIds) === 2);

    $this->postJson("/api/v1/training-requests/{$id}/cancel")->assertStatus(409);
});

it('republica um pedido expirado como pedido novo ligado ao original', function () {
    apiLogin($this->student);
    $id = $this->postJson('/api/v1/training-requests', payload())->json('data.id'); // expira (sem formadores)

    verifiedTrainers(1);

    $this->postJson("/api/v1/training-requests/{$id}/republish", ['amount' => 30000])
        ->assertCreated()
        ->assertJsonPath('data.state', 'searching')
        ->assertJsonPath('data.amount', 30000)
        ->assertJsonPath('data.republished_from_id', $id);

    // Republicar outra vez o mesmo original seria um duplicado do pedido novo, que ainda está à procura
    $this->postJson("/api/v1/training-requests/{$id}/republish")->assertStatus(409)->assertJsonPath('error.code', 'duplicate_request');
    expect(TrainingRequest::query()->count())->toBe(2);
});

it('não deixa ver nem cancelar pedidos de outros alunos', function () {
    $other = TrainingRequest::factory()->create();
    apiLogin($this->student);

    $this->getJson("/api/v1/training-requests/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/training-requests/{$other->id}/cancel")->assertForbidden();
});
