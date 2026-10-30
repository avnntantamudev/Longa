<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Sanctions\Pages\ListSanctions;
use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Notifications\Notifications\ProviderSanctionNotification;
use App\Modules\Ratings\Models\ProviderSanction;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| BISCA, Fase 3.3: avaliação nos dois sentidos, aberta só depois de pago; a média alimenta a reputação e a ordem do
| despacho; regras graduais de aviso, suspensão e remoção (revista pela equipa).
*/

beforeEach(function () {
    Notification::fake();
});

function paidRequest(?ProviderProfile $provider = null): ServiceRequest
{
    $session = acceptedSession('cash', $provider ? ['provider_profile_id' => $provider->id] : []);
    $session->forceFill(['state' => RequestState::Paid, 'confirmed_at' => now()])->save();

    return $session->fresh() ?? $session;
}

/**
 * Dá N avaliações de clientes diferentes ao técnico (cada uma num pedido pago).
 */
function giveRatings(ProviderProfile $provider, int $count, int $stars): void
{
    $service = app(RatingService::class);
    foreach (range(1, $count) as $i) {
        $request = paidRequest($provider);
        $service->rate($request, $request->client, $stars, null);
    }
}

it('avaliação nos dois sentidos, só depois de pago e dentro do prazo', function () {
    $session = acceptedSession('cash');
    apiLogin($session->client);
    $this->postJson("/api/v1/sessions/{$session->id}/ratings", ['stars' => 5])->assertStatus(409)->assertJsonPath('error.code', 'rating_not_open');

    $session->forceFill(['state' => RequestState::Paid, 'confirmed_at' => now()])->save();
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['confirm_cash', 'rate']);
    $this->postJson("/api/v1/sessions/{$session->id}/ratings", ['stars' => 4, 'comment' => 'Pontual e cuidadoso'])->assertCreated()->assertJsonPath('data.direction', 'client_to_provider');
    $this->postJson("/api/v1/sessions/{$session->id}/ratings", ['stars' => 1])->assertOk()->assertJsonPath('data.stars', 4); // uma só

    apiLogin($session->provider->user);
    $this->postJson("/api/v1/sessions/{$session->id}/ratings", ['stars' => 5])->assertCreated()->assertJsonPath('data.direction', 'provider_to_client');

    // O técnico vê a média do cliente; o cliente vê a do técnico
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.client.rating', ['average' => 5, 'count' => 1]);
    expect($session->provider->fresh()?->only(['rating_average', 'rating_count']))->toBe(['rating_average' => 4.0, 'rating_count' => 1]);

    // Terceiros não avaliam; depois do prazo já não se avalia
    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->postJson("/api/v1/sessions/{$session->id}/ratings", ['stars' => 1])->assertForbidden();
    $late = paidRequest();
    $this->travel(8)->days();
    apiLogin($late->client);
    $this->postJson("/api/v1/sessions/{$late->id}/ratings", ['stars' => 3])->assertStatus(410);
});

it('a reputação entra na ordem do despacho', function () {
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $good = ProviderProfile::factory()->verified(50)->near($lat, $lng, 1000)->withCategories($sgme)->create();
    $bad = ProviderProfile::factory()->verified(50)->near($lat, $lng, 900)->withCategories($sgme)->create();
    giveRatings($good, 3, 5);
    giveRatings($bad, 3, 2);

    $request = ServiceRequest::factory()->forCategory($sgme)->create(['pricing_mode' => 'hourly_package', 'max_hourly_rate' => 20000]);
    $ids = array_map(fn ($c) => $c->providerProfileId, app(ProviderMatcher::class)->candidates($request, 10, radiusM: 5000));

    expect(array_search($good->id, $ids, true))->toBeLessThan(array_search($bad->id, $ids, true));
});

it('aviso abaixo de 3,5 (com pelo menos 5 avaliações)', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    giveRatings($provider, 5, 3);

    $warning = ProviderSanction::query()->where('provider_profile_id', $provider->id)->sole();
    expect([$warning->level, $warning->average])->toBe([ProviderSanction::WARNING, 3.0])
        ->and($provider->fresh()?->verification_status)->toBe(VerificationStatus::Verified);
    Notification::assertSentTo($provider->user, ProviderSanctionNotification::class);
});

it('suspensão de 7 dias abaixo de 3,0 (com pelo menos 10): deixa de receber pedidos e volta depois', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    giveRatings($provider, 10, 2);

    $suspension = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::SUSPENSION)->sole();
    expect($provider->fresh()?->verification_status)->toBe(VerificationStatus::Suspended)
        ->and($suspension->ends_at?->isSameDay(now()->addDays(7)))->toBeTrue();

    $this->travel(8)->days();
    app(RatingService::class)->liftExpiredSuspensions();
    expect($provider->fresh()?->verification_status)->toBe(VerificationStatus::Verified)
        ->and($suspension->fresh()?->status)->toBe(ProviderSanction::ENDED);
});

it('remoção abaixo de 2,5 (com pelo menos 20) fica à espera da equipa, que confirma com nota', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    giveRatings($provider, 20, 1);

    $removal = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::REMOVAL)->sole();
    expect($removal->status)->toBe(ProviderSanction::PENDING_REVIEW);

    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(ListSanctions::class)->callTableAction('confirm', $removal, data: ['note' => 'Queixas repetidas de clientes'])->assertHasNoTableActionErrors();

    expect($removal->fresh()?->status)->toBe(ProviderSanction::CONFIRMED)
        ->and($provider->fresh()?->verification_status)->toBe(VerificationStatus::Removed)
        ->and(App\Models\AuditLog::query()->where('action', 'ratings.removal_confirmed')->exists())->toBeTrue();
});

it('à 3.ª suspensão a remoção vai para a equipa', function () {
    $provider = ProviderProfile::factory()->verified()->create();
    foreach (range(1, 2) as $i) {
        ProviderSanction::query()->create(['provider_profile_id' => $provider->id, 'level' => 'suspension', 'status' => 'ended', 'average' => 2.5, 'ratings_count' => 10, 'reason' => 'x']);
    }
    giveRatings($provider, 10, 2);

    expect(ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::SUSPENSION)->count())->toBe(3);
    // A avaliação seguinte avalia de novo: com 3 suspensões, remoção para revisão
    giveRatings($provider, 1, 2);
    expect(ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::REMOVAL)->value('status'))->toBe(ProviderSanction::PENDING_REVIEW)
        ->and(Rating::query()->count())->toBe(11);
});
