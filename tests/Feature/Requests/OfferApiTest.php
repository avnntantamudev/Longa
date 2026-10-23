<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([RequestMatched::class]);
    $sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $this->trainers = array_map(
        fn (int $i) => TrainerProfile::factory()->verified(60 + $i)->near($lat, $lng, 300 * $i)->withSkills($sgme)->create(),
        [1, 2, 3],
    );
    $this->request = TrainingRequest::factory()->forSkill($sgme)->create();
    app(MatchingCoordinator::class)->start($this->request);
});

function offerOf(TrainerProfile $trainer): RequestOffer
{
    return RequestOffer::query()->where('trainer_profile_id', $trainer->id)->firstOrFail();
}

it('mostra ao formador só os dados permitidos antes de aceitar', function () {
    apiLogin($this->trainers[0]->user);

    $response = $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1, 'data');
    $offer = $response->json('data.0');

    expect($offer)->toHaveKeys(['title', 'amount', 'trainer_net', 'neighborhood', 'distance_m', 'seconds_left'])
        ->not->toHaveKeys(['latitude', 'longitude', 'student', 'phone'])
        ->and($offer['distance_m'] % 100)->toBe(0)
        ->and($offer['trainer_net'])->toBe(21250);
    expect($response->getContent())->not->toContain($this->request->student->phone);
});

it('aceita: cria a aula, as outras ofertas perdem e o pedido fica matched', function () {
    apiLogin($this->trainers[1]->user);
    $offer = offerOf($this->trainers[1]);

    $this->postJson("/api/v1/offers/{$offer->id}/view")->assertOk()->assertJsonPath('data.state', 'viewed');

    $response = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    $response->assertJsonPath('data.state', 'scheduled')
        ->assertJsonPath('data.role', 'trainer')
        ->assertJsonPath('data.trainer_net', 21250)
        ->assertJsonPath('data.latitude', TrainerProfileFactory::TALATONA[0]);

    $session = TrainingSession::query()->sole();
    expect($session->escrow_reference)->toStartWith('MOCK-')
        ->and($session->conversation)->not->toBeNull()
        ->and($this->request->fresh()?->state)->toBe(RequestState::Matched)
        ->and($this->request->fresh()?->assigned_trainer_profile_id)->toBe($this->trainers[1]->id)
        ->and(RequestOffer::query()->where('state', 'lost')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'requests.offer_accepted')->exists())->toBeTrue();

    Event::assertDispatched(RequestMatched::class, fn ($e) => count($e->loserUserIds) === 2);
});

it('é idempotente para o formador que ganhou', function () {
    apiLogin($this->trainers[0]->user);
    $offer = offerOf($this->trainers[0]);

    $first = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');
    $second = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');

    expect($second)->toBe($first)->and(TrainingSession::query()->count())->toBe(1);
});

it('responde ao perdedor que outro formador aceitou primeiro', function () {
    apiLogin($this->trainers[0]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->trainers[0])->id.'/accept')->assertOk();

    apiLogin($this->trainers[1]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->trainers[1])->id.'/accept')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_already_matched')
        ->assertJsonPath('error.message', 'Outro formador aceitou este pedido primeiro.');
});

it('impõe o prazo de 60 s no servidor, mesmo antes do job de expiração correr', function () {
    apiLogin($this->trainers[0]->user);
    $offer = offerOf($this->trainers[0]);

    $this->travel(61)->seconds();

    $this->postJson("/api/v1/offers/{$offer->id}/accept")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'offer_expired');

    expect(TrainingSession::query()->count())->toBe(0)
        ->and($offer->fresh()?->state)->toBe(OfferState::Sent);
});

it('recusar regista um evento de score e impede aceitar depois', function () {
    apiLogin($this->trainers[0]->user);
    $offer = offerOf($this->trainers[0]);

    $this->postJson("/api/v1/offers/{$offer->id}/decline")->assertOk()->assertJsonPath('data.state', 'declined');
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'offer_declined');

    expect(ScoreEvent::query()->where('type', ScoreEvent::OFFER_DECLINED)->where('trainer_profile_id', $this->trainers[0]->id)->exists())->toBeTrue();
});

it('não aceita ofertas de pedidos cancelados', function () {
    apiLogin($this->request->student);
    $this->postJson("/api/v1/training-requests/{$this->request->id}/cancel")->assertOk();

    apiLogin($this->trainers[0]->user);
    $this->postJson('/api/v1/offers/'.offerOf($this->trainers[0])->id.'/accept')->assertStatus(410);
});

it('um formador não vê nem responde às ofertas de outro', function () {
    apiLogin($this->trainers[0]->user);
    $foreign = offerOf($this->trainers[1]);

    $this->getJson("/api/v1/offers/{$foreign->id}")->assertForbidden();
    $this->postJson("/api/v1/offers/{$foreign->id}/accept")->assertForbidden();
});

it('alunos não acedem às ofertas', function () {
    apiLogin($this->request->student);

    $this->getJson('/api/v1/offers')->assertForbidden();
});

it('formador só fica disponível se estiver verificado e completo', function () {
    $unverified = TrainerProfile::factory()->create(['is_available' => false]);
    apiLogin($unverified->user);

    $this->putJson('/api/v1/me/trainer-profile/availability', ['is_available' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'trainer_not_ready');

    apiLogin($this->trainers[0]->user);
    $this->putJson('/api/v1/me/trainer-profile/availability', ['is_available' => false])
        ->assertOk()->assertJsonPath('data.is_available', false);
});
