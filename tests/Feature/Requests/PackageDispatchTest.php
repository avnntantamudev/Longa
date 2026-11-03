<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| Envio dos pacotes (passo 4): só técnicos livres em todas as aulas e com a avaliação mínima; durante a janela
| (20 min) só técnicos VIP, depois todos (ou só VIP até ao fecho, com "só VIP" ligado). Ao aceitar, volta a
| confirmar que o técnico continua livre em todas as aulas.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 08:00', 'Africa/Luanda')); // segunda-feira
    Event::fake([OfferSent::class]);
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->vip = pkgDispatchProvider($this->sgme, vip: true);
    $this->regular = pkgDispatchProvider($this->sgme);
    $this->vipBusy = pkgDispatchProvider($this->sgme, vip: true);
    $this->vipLowRating = pkgDispatchProvider($this->sgme, vip: true);
    $this->vipLowRating->forceFill(['rating_count' => 6, 'rating_average' => 3.5])->save();
    pkgDispatchBusy($this->vipBusy, $this->sgme, pkgDispatchLesson(2)); // ocupado na quarta às 9h
    apiLogin(User::factory()->withRole(Role::Client)->create());
});

function pkgDispatchProvider(Category $category, bool $vip = false): ProviderProfile
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, 700)->withCategories($category)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $provider->id)->update(['hourly_rate' => 5000]);
    if ($vip) {
        app(VipSubscriptions::class)->grant($provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
    }

    return $provider;
}

/** Aula N dias depois de hoje, às 9h de Luanda. */
function pkgDispatchLesson(int $days): Carbon
{
    return now('Africa/Luanda')->addDays($days)->setTime(9, 0);
}

function pkgDispatchBusy(ProviderProfile $provider, Category $category, Carbon $at): ServiceRequest
{
    $busy = ServiceRequest::factory()->create(['category_id' => $category->id, 'scheduled_at' => $at, 'duration_minutes' => 60]);
    $busy->forceFill(['state' => RequestState::Accepted->value, 'provider_profile_id' => $provider->id])->save();

    return $busy;
}

function pkgDispatchCreate(Category $category): ServiceRequest
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $id = test()->postJson('/api/v1/service-requests', [
        'category_id' => $category->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'max_hourly_rate' => 10000, 'duration_minutes' => 60,
        'lesson_dates' => array_map(fn (int $d) => pkgDispatchLesson($d)->toIso8601String(), [1, 2, 3]),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');

    return ServiceRequest::query()->findOrFail($id);
}

/** @return list<int> */
function pkgDispatchOffered(ServiceRequest $request): array
{
    return RequestOffer::query()->where('service_request_id', $request->id)->orderBy('provider_profile_id')->pluck('provider_profile_id')->all();
}

it('primeiro só técnicos VIP livres em todas as aulas e com boa avaliação; depois da janela, todos', function () {
    $request = pkgDispatchCreate($this->sgme);

    expect($request->state)->toBe(RequestState::Searching)
        ->and($request->scheduled)->toBeTrue()
        ->and(pkgDispatchOffered($request))->toBe([$this->vip->id]);

    // Ainda dentro dos 20 minutos: nada muda
    $this->travel(15)->minutes();
    app(MatchingCoordinator::class)->sweep();
    expect(pkgDispatchOffered($request))->toBe([$this->vip->id]);

    // Fim da janela: abre a todos os elegíveis (nunca a quem está ocupado numa aula ou tem avaliação baixa)
    $this->travel(6)->minutes();
    app(MatchingCoordinator::class)->sweep();
    expect(pkgDispatchOffered($request))->toBe([$this->vip->id, $this->regular->id]);
});

it('com "só VIP" ligado, o pacote nunca abre aos outros técnicos', function () {
    config(['longa.packages.vip_only' => 1]);
    $request = pkgDispatchCreate($this->sgme);

    $this->travel(3)->hours();
    app(MatchingCoordinator::class)->sweep();

    expect(pkgDispatchOffered($request))->toBe([$this->vip->id]);
});

it('a oferta mostra o calendário das aulas ao técnico', function () {
    $request = pkgDispatchCreate($this->sgme);
    $offer = RequestOffer::query()->where('service_request_id', $request->id)->firstOrFail();

    apiLogin($this->vip->user);
    $this->getJson("/api/v1/offers/{$offer->id}")->assertOk()
        ->assertJsonPath('data.sessions', 3)
        ->assertJsonPath('data.duration_minutes', 60)
        ->assertJsonCount(3, 'data.lesson_schedule');
});

it('ao aceitar volta a confirmar que o técnico está livre em todas as aulas', function () {
    $request = pkgDispatchCreate($this->sgme);
    $offer = RequestOffer::query()->where('service_request_id', $request->id)->firstOrFail();

    // Entretanto ganhou outro serviço na quinta às 9h (3.ª aula)
    $other = pkgDispatchBusy($this->vip, $this->sgme, pkgDispatchLesson(3));
    apiLogin($this->vip->user);
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'schedule_conflict');

    // Livre outra vez: aceita o pacote ao seu preço por hora, com a comissão VIP
    $other->forceFill(['state' => RequestState::Cancelled->value])->save();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();
    expect($request->fresh())
        ->state->toBe(RequestState::Accepted)
        ->provider_profile_id->toBe($this->vip->id)
        ->amount->toBe(HourlyPricing::total(5000, 60, 3, 0))
        ->commission_percent->toBe(10);
});

it('um pacote não pode ser pedido direto nem começar demasiado cedo', function () {
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $base = [
        'category_id' => $this->sgme->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'], 'max_hourly_rate' => 10000,
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ];

    // Às 23h, uma 1.ª aula amanhã à 1h fica a menos de 2 h (fecho) + 20 min (janela VIP) + 1 h (resposta)
    $this->travelTo(Carbon::parse('2026-11-09 23:00', 'Africa/Luanda'));
    apiLogin(User::factory()->withRole(Role::Client)->create());
    $early = [now('Africa/Luanda')->addDay()->setTime(1, 0)->toIso8601String(), now('Africa/Luanda')->addDays(2)->setTime(9, 0)->toIso8601String()];
    $this->postJson('/api/v1/service-requests', [...$base, 'lesson_dates' => $early])
        ->assertUnprocessable()->assertJsonValidationErrorFor('lesson_dates', 'error.details');

    $this->postJson('/api/v1/service-requests', [...$base, 'lesson_dates' => [pkgDispatchLesson(2)->toIso8601String(), pkgDispatchLesson(3)->toIso8601String()], 'direct_provider_id' => $this->vip->id])
        ->assertUnprocessable()->assertJsonValidationErrorFor('direct_provider_id', 'error.details');
});
