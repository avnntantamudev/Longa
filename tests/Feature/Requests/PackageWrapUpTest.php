<?php

declare(strict_types=1);

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Reports\CompanyServicesReport;
use App\Modules\Payments\Models\ProviderSubscription;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Modules\Wallet\Services\EarningsReport;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Fecho dos pacotes (passo 7): avaliação no fim (no pacote) e, opcional, na aula do meio; o VIP que expira a meio
| não muda as condições do pacote; os relatórios contam as aulas, não o pedido-contrato.
*/

beforeEach(function () {
    Notification::fake();
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->provider = ProviderProfile::factory()->verified()->withCategories($this->sgme)->create();
    $this->client = User::factory()->withRole(Role::Client)->create();
});

/**
 * Pacote já terminado (pago) com N aulas pagas, construído diretamente.
 *
 * @return array{0: ServiceRequest, 1: list<ServiceRequest>}
 */
function wrapPackage(object $t, int $lessons): array
{
    $dates = array_map(fn (int $d) => now()->subDays(10 - $d)->setTime(9, 0), range(1, $lessons));
    $package = ServiceRequest::factory()->create([
        'client_id' => $t->client->id, 'category_id' => $t->sgme->id, 'scheduled_at' => $dates[0], 'amount' => 5000 * $lessons,
        'lesson_schedule' => array_map(fn (Carbon $d) => $d->toIso8601String(), $dates),
    ]);
    $package->forceFill(['state' => RequestState::Paid->value, 'provider_profile_id' => $t->provider->id, 'sessions' => $lessons, 'confirmed_at' => now()])->save();

    $children = [];
    foreach ($dates as $i => $date) {
        $lesson = ServiceRequest::factory()->create([
            'client_id' => $t->client->id, 'category_id' => $t->sgme->id, 'scheduled_at' => $date, 'amount' => 5000,
            'package_id' => $package->id, 'package_sequence' => $i + 1,
        ]);
        $lesson->forceFill(['state' => RequestState::Paid->value, 'provider_profile_id' => $t->provider->id, 'confirmed_at' => now()])->save();
        $children[] = $lesson;
    }

    return [$package, $children];
}

it('avalia-se o pacote no fim e, opcionalmente, a aula do meio (pacotes com 4 ou mais aulas)', function () {
    $ratings = app(RatingService::class);
    [$package, $lessons] = wrapPackage($this, 4);

    expect($ratings->canRate($package, $this->client))->toBeTrue()
        ->and($ratings->canRate($lessons[1], $this->client))->toBeTrue() // 2.ª de 4
        ->and($ratings->canRate($lessons[0], $this->client))->toBeFalse()
        ->and($ratings->canRate($lessons[2], $this->client))->toBeFalse()
        ->and($ratings->canRate($lessons[3], $this->client))->toBeFalse()
        ->and($ratings->canRate($package, $this->provider->user))->toBeTrue();

    // Pacotes curtos: só a avaliação final
    [$short, $shortLessons] = wrapPackage($this, 3);
    expect(collect($shortLessons)->contains(fn ($l) => $ratings->canRate($l, $this->client)))->toBeFalse()
        ->and($ratings->canRate($short, $this->client))->toBeTrue();
});

it('os relatórios contam as aulas, não o pedido-contrato', function () {
    [, $lessons] = wrapPackage($this, 3);

    $earnings = app(EarningsReport::class)->build($this->provider, (int) now()->year);
    expect($earnings['totals']['lessons'])->toBe(3);

    $company = Company::query()->create(['name' => 'Escola X']);
    DB::table('company_employees')->insert([
        'company_id' => $company->id, 'name' => 'Formador', 'phone' => $this->provider->user->phone, 'user_id' => $this->provider->user_id,
        'status' => 'active', 'consent_status' => 'accepted', 'consent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $report = app(CompanyServicesReport::class)->build($company, now()->subMonth(), now());
    expect($report['provided']['totals']['services'])->toBe(3)
        ->and($report['provided']['totals']['amount'])->toBe(collect($lessons)->sum('amount'));
});

it('o VIP que expira a meio do pacote não muda a comissão das aulas', function () {
    Cache::flush();
    Event::fake([OfferSent::class, SessionStarted::class, QrIssued::class, SessionConfirmed::class, WalletUpdated::class]);
    $this->travelTo(Carbon::parse('2026-11-09 08:00', 'Africa/Luanda'));
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $vip = ProviderProfile::factory()->verified()->near($lat, $lng, 600)->withCategories($this->sgme)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $vip->id)->update(['hourly_rate' => 5000]);
    app(VipSubscriptions::class)->grant($vip, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');

    apiLogin($this->client);
    $id = $this->postJson('/api/v1/service-requests', [
        'category_id' => $this->sgme->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'], 'max_hourly_rate' => 10000, 'duration_minutes' => 60,
        'lesson_dates' => array_map(fn (int $d) => now('Africa/Luanda')->addDays($d)->setTime(9, 0)->toIso8601String(), [1, 2]),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');
    apiLogin($vip->user);
    $this->postJson('/api/v1/offers/'.RequestOffer::query()->where('service_request_id', $id)->value('id').'/accept')->assertOk();
    apiLogin($this->client);
    $this->postJson("/api/v1/sessions/{$id}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => 'wrap-pay'])->assertOk();

    // O VIP acaba antes da 1.ª aula
    ProviderSubscription::query()->where('provider_profile_id', $vip->id)->update(['ends_at' => now()->addHour()]);
    $lesson = ServiceRequest::query()->where('package_id', $id)->orderBy('package_sequence')->firstOrFail();
    $this->travelTo($lesson->scheduled_at->copy()->subMinutes(5));
    expect(app(VipSubscriptions::class)->isVip($vip))->toBeFalse();

    apiLogin($vip->user);
    $this->postJson("/api/v1/sessions/{$lesson->id}/start")->assertOk();
    $this->travel(60)->minutes();
    $qr = $this->postJson("/api/v1/sessions/{$lesson->id}/finish")->assertOk()->json('qr');
    apiLogin($this->client);
    $this->postJson("/api/v1/sessions/{$lesson->id}/confirm", ['token' => $qr['tokens'][0]['token']], ['Idempotency-Key' => 'wrap-confirm'])->assertOk();

    apiLogin($vip->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', $lesson->amount - intdiv($lesson->amount * 10 + 50, 100));
});
