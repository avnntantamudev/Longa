<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;

beforeEach(function () {
    $this->sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $this->trainer = TrainerProfile::factory()->verified()->near($lat, $lng, 500)->withSkills($this->sgme)->create();
});

function newOfferFor(TrainerProfile $trainer, Skill $skill): RequestOffer
{
    $request = TrainingRequest::factory()->forSkill($skill)->create();
    app(MatchingCoordinator::class)->start($request);

    return RequestOffer::query()->where('training_request_id', $request->id)->where('trainer_profile_id', $trainer->id)->firstOrFail();
}

it('regista recusas repetidas e ofertas sem resposta e mostra o resumo ao formador', function () {
    apiLogin($this->trainer->user);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/offers/'.newOfferFor($this->trainer, $this->sgme)->id.'/decline')->assertOk();
    }
    newOfferFor($this->trainer, $this->sgme);
    $this->travel(61)->seconds();
    $this->artisan('longa:matching-tick');

    $this->getJson('/api/v1/me/score-events')
        ->assertOk()
        ->assertJsonPath('summary_30_days.offer_declined', 3)
        ->assertJsonPath('summary_30_days.offer_expired', 1)
        ->assertJsonCount(4, 'data');

    expect(ScoreEvent::query()->sum('points'))->toBe(0);
});

it('o admin vê os eventos de um formador; outros não', function () {
    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->getJson("/api/v1/admin/trainers/{$this->trainer->id}/score-events")->assertOk();

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->getJson("/api/v1/admin/trainers/{$this->trainer->id}/score-events")->assertForbidden();
});

it('um formador suspenso deixa de receber pedidos e não consegue aceitar ofertas já recebidas', function () {
    apiLogin($this->trainer->user);
    $offer = newOfferFor($this->trainer, $this->sgme);

    $this->trainer->user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->app['auth']->forgetGuards();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertUnauthorized();

    $next = TrainingRequest::factory()->forSkill($this->sgme)->create();
    app(MatchingCoordinator::class)->start($next);
    expect(RequestOffer::query()->where('training_request_id', $next->id)->count())->toBe(0);
});
