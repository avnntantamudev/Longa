<?php

declare(strict_types=1);

use App\Modules\Matching\Services\TrainerMatcher;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function makeVip(TrainerProfile $trainer): void
{
    app(VipSubscriptions::class)->grant($trainer, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
}

/**
 * Publica um pedido pela API e devolve a oferta do formador indicado.
 */
function vipOfferFor(TrainerProfile $trainer, Skill $skill, int $amount = 25000): RequestOffer
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    apiLogin(User::factory()->withRole(Role::Student)->create());
    test()->postJson('/api/v1/training-requests', [
        'skill_id' => $skill->id, 'title' => 'Aula', 'amount' => $amount, 'modality' => 'in_person',
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated();

    return RequestOffer::query()->where('trainer_profile_id', $trainer->id)->latest('id')->firstOrFail();
}

it('formador VIP aceita com comissão de 10%: aula, oferta e escrow coerentes', function () {
    $skill = Skill::query()->firstOrFail();
    $trainer = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    makeVip($trainer);
    $offer = vipOfferFor($trainer, $skill);

    apiLogin($trainer->user);
    $this->getJson('/api/v1/offers')->assertJsonPath('data.0.commission_percent', 10)->assertJsonPath('data.0.trainer_net', 22500);
    $sessionId = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');

    $session = TrainingSession::query()->findOrFail($sessionId);
    expect([$session->commission, $session->trainer_net, $session->commission_percent])->toBe([2500, 22500, 10]);

    app(PayForSession::class)->handle($session, $session->student, 'multicaixa_express');
    expect(EscrowHold::query()->sole()->only(['commission', 'trainer_net', 'commission_percent']))
        ->toBe(['commission' => 2500, 'trainer_net' => 22500, 'commission_percent' => 10]);
});

it('formador normal continua com 15%; a comissão fica fixa mesmo que o VIP comece depois', function () {
    $skill = Skill::query()->firstOrFail();
    $trainer = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $offer = vipOfferFor($trainer, $skill);

    apiLogin($trainer->user);
    $sessionId = $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk()->json('data.id');
    makeVip($trainer);

    expect(TrainingSession::query()->findOrFail($sessionId)->only(['commission', 'commission_percent']))->toBe(['commission' => 3750, 'commission_percent' => 15]);
});

it('na 1.ª onda os VIP vêm primeiro, mesmo com pontuação mais baixa', function () {
    $skill = Skill::query()->firstOrFail();
    $top = TrainerProfile::factory()->verified(95)->withSkills($skill)->create();
    $vip = TrainerProfile::factory()->verified(40)->withSkills($skill)->near(...[...TrainerProfileFactory::TALATONA, 3000])->create();
    makeVip($vip);
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $request = TrainingRequest::factory()->create(['skill_id' => $skill->id, 'latitude' => $lat, 'longitude' => $lng, 'scheduled_at' => now()->addDay()]);

    $ids = array_map(fn ($c) => $c->trainerProfileId, app(TrainerMatcher::class)->candidates($request, 10));

    expect(array_slice($ids, 0, 2))->toBe([$vip->id, $top->id]);
});

it('o selo VIP aparece ao aluno na aula', function () {
    $session = paidSession();
    makeVip($session->trainer);

    apiLogin($session->student);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.trainer.vip', true);
});
