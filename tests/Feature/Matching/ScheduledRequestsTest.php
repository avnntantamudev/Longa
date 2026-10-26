<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function vipTrainer(TrainerProfile $trainer): TrainerProfile
{
    app(VipSubscriptions::class)->grant($trainer, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');

    return $trainer;
}

function publishRequest(Skill $skill, string $when, string $province = 'Luanda'): TrainingRequest
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    apiLogin(User::factory()->withRole(Role::Student)->create());
    $id = test()->postJson('/api/v1/training-requests', [
        'skill_id' => $skill->id, 'title' => 'Aula', 'amount' => 20000, 'modality' => 'in_person',
        'scheduled_at' => $when, 'province' => $province,
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');

    return TrainingRequest::query()->findOrFail($id);
}

it('pedido para outro dia: formadores a 5 km e VIP da província recebem; VIP de outra província e normais longe não', function () {
    $skill = Skill::query()->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $near = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $farVip = vipTrainer(TrainerProfile::factory()->verified()->withSkills($skill)->near($lat, $lng, 40000)->create());
    $farNormal = TrainerProfile::factory()->verified()->withSkills($skill)->near($lat, $lng, 40000)->create();
    $otherProvinceVip = vipTrainer(TrainerProfile::factory()->verified()->withSkills($skill)->near($lat, $lng, 40000)->create(['province' => 'Benguela']));

    $request = publishRequest($skill, now()->addDays(2)->setTime(10, 0)->toIso8601String());

    expect($request->scheduled)->toBeTrue()
        ->and($request->expires_at?->toDateTimeString())->toBe(now()->addDays(2)->setTime(8, 0)->toDateTimeString());
    $offered = RequestOffer::query()->where('training_request_id', $request->id)->pluck('trainer_profile_id')->all();
    expect($offered)->toContain($near->id, $farVip->id)->not->toContain($farNormal->id, $otherProvinceVip->id);
    // Ofertas válidas até ao fecho do pedido; aviso sem SMS
    expect(RequestOffer::query()->where('training_request_id', $request->id)->pluck('expires_at')->unique()->count())->toBe(1);
    Notification::assertSentTo($farVip->user, NewOfferNotification::class);
});

it('pedido para hoje continua TRIM TRIM normal (5 km, 5 minutos)', function () {
    $skill = Skill::query()->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $farVip = vipTrainer(TrainerProfile::factory()->verified()->withSkills($skill)->near($lat, $lng, 40000)->create());

    $this->travelTo(now()->setTime(9, 0));
    $request = publishRequest($skill, now()->setTime(15, 0)->toIso8601String());

    expect($request->scheduled)->toBeFalse()
        ->and(RequestOffer::query()->where('trainer_profile_id', $farVip->id)->exists())->toBeFalse();
});

it('o VIP longe aceita a aula; os outros perdem a oferta', function () {
    $skill = Skill::query()->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $near = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $farVip = vipTrainer(TrainerProfile::factory()->verified()->withSkills($skill)->near($lat, $lng, 30000)->create());
    $request = publishRequest($skill, now()->addDays(3)->setTime(9, 0)->toIso8601String());

    apiLogin($farVip->user);
    $offer = RequestOffer::query()->where('training_request_id', $request->id)->where('trainer_profile_id', $farVip->id)->sole();
    $this->getJson('/api/v1/offers')->assertJsonPath('data.0.scheduled', true);
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    expect($request->fresh()?->state)->toBe(RequestState::Matched)
        ->and(RequestOffer::query()->where('trainer_profile_id', $near->id)->value('state')->value)->toBe('lost');
});

it('sem ninguém, o pedido espera; quem fica elegível depois recebe; expira no fecho sem penalizar ninguém', function () {
    $skill = Skill::query()->firstOrFail();
    $request = publishRequest($skill, now()->addDays(2)->setTime(10, 0)->toIso8601String());
    expect($request->fresh()?->state)->toBe(RequestState::Searching);

    $late = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $this->travel(11)->minutes();
    app(MatchingCoordinator::class)->sweep();
    expect(RequestOffer::query()->where('trainer_profile_id', $late->id)->exists())->toBeTrue();

    $this->travelTo($request->fresh()?->expires_at?->copy()->addMinute());
    app(MatchingCoordinator::class)->sweep();

    expect($request->fresh()?->state)->toBe(RequestState::Expired)
        ->and(ScoreEvent::query()->where('type', ScoreEvent::OFFER_EXPIRED)->count())->toBe(0);
});
