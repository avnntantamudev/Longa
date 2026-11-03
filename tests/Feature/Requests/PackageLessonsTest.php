<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Requests\Services\PackageLessons;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Sessions\Services\SessionTimeoutRules;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Aulas do pacote (passo 5): o pacote paga-se no início e o dinheiro fica retido; cada aula tem o seu ciclo
| (início, fim, QR) e, ao ser confirmada, liberta a sua parte ao técnico (com a comissão VIP de 10%). A última
| aula liberta o resto e fecha o pacote como pago. O pedido-contrato em si não se executa.
*/

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Event::fake([OfferSent::class, SessionStarted::class, QrIssued::class, SessionConfirmed::class, WalletUpdated::class]);
    $this->travelTo(Carbon::parse('2026-11-09 08:00', 'Africa/Luanda'));
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->provider = ProviderProfile::factory()->verified()->near($lat, $lng, 600)->withCategories($this->sgme)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $this->provider->id)->update(['hourly_rate' => 5000]);
    app(VipSubscriptions::class)->grant($this->provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
    $this->client = User::factory()->withRole(Role::Client)->create();
});

/** Pacote de 3 aulas (terça, quarta e quinta às 9h) aceite e pago. */
function lessonsPaidPackage(object $t): ServiceRequest
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    apiLogin($t->client);
    $id = test()->postJson('/api/v1/service-requests', [
        'category_id' => $t->sgme->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'max_hourly_rate' => 10000, 'duration_minutes' => 60,
        'lesson_dates' => array_map(fn (int $d) => now('Africa/Luanda')->addDays($d)->setTime(9, 0)->toIso8601String(), [1, 2, 3]),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->json('data.id');

    apiLogin($t->provider->user);
    $offer = RequestOffer::query()->where('service_request_id', $id)->firstOrFail();
    test()->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    apiLogin($t->client);
    // O pedido-contrato só se paga: as outras ações são das aulas
    test()->getJson("/api/v1/sessions/{$id}")->assertJsonPath('data.actions', ['pay']);
    test()->postJson("/api/v1/sessions/{$id}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => 'pkg-pay-'.$id])->assertOk();

    return ServiceRequest::query()->findOrFail($id);
}

/** Dá uma aula de ponta a ponta: o técnico inicia e termina, o cliente lê o QR. */
function lessonsGive(object $t, ServiceRequest $lesson): void
{
    test()->travelTo($lesson->scheduled_at->copy()->subMinutes(5));
    apiLogin($t->provider->user);
    test()->postJson("/api/v1/sessions/{$lesson->id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');
    test()->travel(60)->minutes();
    $qr = test()->postJson("/api/v1/sessions/{$lesson->id}/finish")->assertOk()->json('qr');
    apiLogin($t->client);
    test()->postJson("/api/v1/sessions/{$lesson->id}/confirm", ['token' => $qr['tokens'][0]['token']], ['Idempotency-Key' => 'pkg-confirm-'.$lesson->id])
        ->assertOk()->assertJsonPath('data.state', 'paid');
}

it('o pagamento do pacote cria uma aula por data, com o técnico e o valor repartido', function () {
    $package = lessonsPaidPackage($this);
    $total = HourlyPricing::total(5000, 60, 3, 0);

    expect($package->amount)->toBe($total)
        ->and($package->payment_status)->toBe(ServiceRequest::PAYMENT_HELD)
        ->and(EscrowHold::query()->where('service_request_id', $package->id)->value('amount'))->toBe($total);

    $lessons = $package->lessons()->get();
    expect($lessons)->toHaveCount(3)
        ->and($lessons->pluck('state')->unique()->all())->toBe([RequestState::Accepted])
        ->and($lessons->pluck('provider_profile_id')->unique()->all())->toBe([$this->provider->id])
        ->and($lessons->sum('amount'))->toBe($total)
        ->and($lessons->pluck('commission_percent')->unique()->all())->toBe([10])
        ->and($lessons->pluck('payment_status')->unique()->all())->toBe([ServiceRequest::PAYMENT_HELD])
        ->and($lessons->first()->scheduled_at->equalTo(Carbon::parse($package->lesson_schedule[0])))->toBeTrue()
        ->and($lessons->last()->title)->toEndWith('aula 3/3');

    // Pagar outra vez não duplica as aulas
    app(PackageLessons::class)->createFor($package->fresh());
    expect($package->lessons()->count())->toBe(3);
});

it('cada aula confirmada liberta a sua parte; a última fecha o pacote como pago', function () {
    $package = lessonsPaidPackage($this);
    $lessons = $package->lessons()->get();
    $hold = fn () => EscrowHold::query()->where('service_request_id', $package->id)->firstOrFail();

    lessonsGive($this, $lessons[0]);
    expect($hold()->amount)->toBe($package->amount - $lessons[0]->amount)
        ->and($hold()->isActive())->toBeTrue()
        ->and($package->fresh()?->state)->toBe(RequestState::Accepted);

    lessonsGive($this, $lessons[1]);
    lessonsGive($this, $lessons[2]);

    expect($hold()->state)->toBe(EscrowHold::RELEASED)
        ->and($package->fresh()?->state)->toBe(RequestState::Paid);

    // O técnico recebeu o total menos 10% de comissão
    apiLogin($this->provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', $package->amount - intdiv($package->amount * 10 + 50, 100));
});

it('as regras automáticas não cancelam nem lembram o pedido-contrato (só as aulas)', function () {
    $package = lessonsPaidPackage($this);

    // Passa a 1.ª aula sem ninguém a iniciar: a aula é tratada pelas regras, o pacote não
    $this->travelTo(Carbon::parse($package->lesson_schedule[0])->addHours(30));
    app(SessionTimeoutRules::class)->run();

    expect($package->fresh()?->state)->toBe(RequestState::Accepted)
        ->and($package->lessons()->first()?->state)->toBe(RequestState::Cancelled);
});
