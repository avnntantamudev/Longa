<?php

declare(strict_types=1);

use App\Modules\Matching\Services\TrainerMatcher;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use Database\Factories\TrainerProfileFactory;

[$lat, $lng] = TrainerProfileFactory::TALATONA;

beforeEach(function () {
    $this->sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->request = TrainingRequest::factory()->forSkill($this->sgme)->create();
});

function trainerAt(int $metersNorth, ?Skill $skill = null, int $score = 50, array $state = []): TrainerProfile
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;

    return TrainerProfile::factory()
        ->verified($score)
        ->near($lat, $lng, $metersNorth)
        ->withSkills($skill ?? test()->sgme)
        ->create($state);
}

function matchedIds(int $limit = 50, array $exclude = []): array
{
    return array_map(fn ($c) => $c->trainerProfileId, app(TrainerMatcher::class)->candidates(test()->request, $limit, $exclude));
}

it('encontra formadores dentro de 5 km e calcula a distância', function () {
    $near = trainerAt(1200);
    $far = trainerAt(6000);

    $candidates = app(TrainerMatcher::class)->candidates($this->request, 10);

    expect(array_map(fn ($c) => $c->trainerProfileId, $candidates))->toBe([$near->id])
        ->and($candidates[0]->distanceM)->toBeBetween(1150, 1250);
    expect($far->id)->toBeInt();
});

it('respeita o raio de atuação do próprio formador', function () {
    $smallRadius = trainerAt(3000, state: ['service_radius_m' => 2000]);
    $bigRadius = trainerAt(3000, state: ['service_radius_m' => 5000]);

    expect(matchedIds())->toBe([$bigRadius->id]);
    expect($smallRadius->id)->toBeInt();
});

it('exige a área de formação do pedido', function () {
    $other = Skill::query()->where('slug', 'matematica')->firstOrFail();
    trainerAt(500, $other);
    $right = trainerAt(800);

    expect(matchedIds())->toBe([$right->id]);
});

it('exclui indisponíveis, não verificados, sem onboarding e suspensos', function () {
    trainerAt(300, state: ['is_available' => false]);
    TrainerProfile::factory()->near(TrainerProfileFactory::TALATONA[0], TrainerProfileFactory::TALATONA[1], 300)->withSkills($this->sgme)->create();
    trainerAt(300, state: ['onboarding_completed_at' => null]);
    $suspended = trainerAt(300);
    $suspended->user->forceFill(['status' => UserStatus::Suspended])->save();
    $ok = trainerAt(400);

    expect(matchedIds())->toBe([$ok->id]);
});

it('exclui o próprio aluno e quem já recebeu oferta', function () {
    $self = trainerAt(100, state: ['user_id' => $this->request->student_id]);
    $offered = trainerAt(200);
    $fresh = trainerAt(300);

    expect(matchedIds(exclude: [$offered->id]))->toBe([$fresh->id]);
    expect($self->id)->toBeInt();
});

it('exclui formadores com aula marcada à mesma hora', function () {
    $busy = trainerAt(200);
    $free = trainerAt(300);

    TrainingSession::query()->create([
        'training_request_id' => TrainingRequest::factory()->create()->id, 'student_id' => $this->request->student_id,
        'trainer_profile_id' => $busy->id, 'skill_id' => $this->sgme->id, 'title' => 'Outra aula',
        'amount' => 1000, 'commission' => 150, 'trainer_net' => 850,
        'scheduled_at' => $this->request->scheduled_at->copy()->addHour(), 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => -8.9, 'longitude' => 13.1,
    ]);

    expect(matchedIds())->toBe([$free->id]);
});

it('ordena por score e proximidade com os pesos configurados', function () {
    $closeLowScore = trainerAt(200, score: 40);
    $farHighScore = trainerAt(4500, score: 95);
    $midMidScore = trainerAt(2000, score: 70);

    // Por defeito 0,6 × score + 0,4 × proximidade: 0,66 (2 km, 70) > 0,624 (200 m, 40) > 0,61 (4,5 km, 95)
    expect(matchedIds())->toBe([$midMidScore->id, $closeLowScore->id, $farHighScore->id]);

    // O score domina
    config(['longa.matching.weight_score' => 0.9, 'longa.matching.weight_distance' => 0.1]);
    expect(matchedIds())->toBe([$farHighScore->id, $midMidScore->id, $closeLowScore->id]);

    // A proximidade domina
    config(['longa.matching.weight_score' => 0.1, 'longa.matching.weight_distance' => 0.9]);
    expect(matchedIds())->toBe([$closeLowScore->id, $midMidScore->id, $farHighScore->id]);
});

it('respeita o limite (tamanho da onda)', function () {
    foreach (range(1, 5) as $i) {
        trainerAt($i * 300);
    }

    expect(matchedIds(limit: 3))->toHaveCount(3);
});

it('pedidos online ignoram a distância mas exigem a modalidade online', function () {
    $this->request->update(['modality' => 'online']);
    $farOnline = trainerAt(50000, state: ['modalities' => ['online']]);
    trainerAt(100, state: ['modalities' => ['in_person']]);

    expect(matchedIds())->toBe([$farOnline->id]);
});
