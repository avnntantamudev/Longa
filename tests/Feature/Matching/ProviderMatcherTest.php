<?php

declare(strict_types=1);

use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Database\Factories\ProviderProfileFactory;

[$lat, $lng] = ProviderProfileFactory::TALATONA;

beforeEach(function () {
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->request = ServiceRequest::factory()->forCategory($this->sgme)->create();
});

function providerAt(int $metersNorth, ?Category $category = null, int $score = 50, array $state = []): ProviderProfile
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return ProviderProfile::factory()
        ->verified($score)
        ->near($lat, $lng, $metersNorth)
        ->withCategories($category ?? test()->sgme)
        ->create($state);
}

function matchedIds(int $limit = 50, array $exclude = []): array
{
    return array_map(fn ($c) => $c->providerProfileId, app(ProviderMatcher::class)->candidates(test()->request, $limit, $exclude));
}

it('encontra técnicos dentro de 5 km e calcula a distância', function () {
    $near = providerAt(1200);
    $far = providerAt(6000);

    $candidates = app(ProviderMatcher::class)->candidates($this->request, 10);

    expect(array_map(fn ($c) => $c->providerProfileId, $candidates))->toBe([$near->id])
        ->and($candidates[0]->distanceM)->toBeBetween(1150, 1250);
    expect($far->id)->toBeInt();
});

it('respeita o raio de atuação do próprio técnico', function () {
    $smallRadius = providerAt(3000, state: ['service_radius_m' => 2000]);
    $bigRadius = providerAt(3000, state: ['service_radius_m' => 5000]);

    expect(matchedIds())->toBe([$bigRadius->id]);
    expect($smallRadius->id)->toBeInt();
});

it('exige a área de serviço do pedido', function () {
    $other = Category::query()->where('slug', 'matematica')->firstOrFail();
    providerAt(500, $other);
    $right = providerAt(800);

    expect(matchedIds())->toBe([$right->id]);
});

it('exclui indisponíveis, não verificados, sem onboarding e suspensos', function () {
    providerAt(300, state: ['is_available' => false]);
    ProviderProfile::factory()->near(ProviderProfileFactory::TALATONA[0], ProviderProfileFactory::TALATONA[1], 300)->withCategories($this->sgme)->create();
    providerAt(300, state: ['onboarding_completed_at' => null]);
    $suspended = providerAt(300);
    $suspended->user->forceFill(['status' => UserStatus::Suspended])->save();
    $ok = providerAt(400);

    expect(matchedIds())->toBe([$ok->id]);
});

it('exclui o próprio cliente e quem já recebeu oferta', function () {
    $self = providerAt(100, state: ['user_id' => $this->request->client_id]);
    $offered = providerAt(200);
    $fresh = providerAt(300);

    expect(matchedIds(exclude: [$offered->id]))->toBe([$fresh->id]);
    expect($self->id)->toBeInt();
});

it('exclui técnicos com serviço marcado à mesma hora', function () {
    $busy = providerAt(200);
    $free = providerAt(300);

    acceptedSession('cash', [
        'client_id' => $this->request->client_id, 'provider_profile_id' => $busy->id, 'category_id' => $this->sgme->id,
        'scheduled_at' => $this->request->scheduled_at->copy()->addHour(),
    ]);

    expect(matchedIds())->toBe([$free->id]);
});

it('ordena por score e proximidade com os pesos configurados', function () {
    $closeLowScore = providerAt(200, score: 40);
    $farHighScore = providerAt(4500, score: 95);
    $midMidScore = providerAt(2000, score: 70);

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
        providerAt($i * 300);
    }

    expect(matchedIds(limit: 3))->toHaveCount(3);
});

it('pedidos online ignoram a distância mas exigem a modalidade online', function () {
    $this->request->update(['modality' => 'online']);
    $farOnline = providerAt(50000, state: ['modalities' => ['online']]);
    providerAt(100, state: ['modalities' => ['in_person']]);

    expect(matchedIds())->toBe([$farOnline->id]);
});
