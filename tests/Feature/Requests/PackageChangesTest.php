<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\PackageUpdateNotification;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
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
| Desistências, remarcações e faltas nos pacotes (passo 6). Cada aula fecha a partir da retenção do pacote:
| a parte do técnico é libertada e a do cliente devolvida (uma devolução por aula, sem pagar duas vezes).
*/

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Event::fake([OfferSent::class, SessionStarted::class, QrIssued::class, SessionConfirmed::class, WalletUpdated::class]);
    $this->travelTo(Carbon::parse('2026-11-09 08:00', 'Africa/Luanda')); // segunda-feira
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $this->provider = ProviderProfile::factory()->verified()->near($lat, $lng, 600)->withCategories($this->sgme)->create();
    DB::table('category_provider_profile')->where('provider_profile_id', $this->provider->id)->update(['hourly_rate' => 5000]);
    app(VipSubscriptions::class)->grant($this->provider, User::factory()->withRole(Role::Admin)->create(), 'courtesy', 'Teste');
    $this->client = User::factory()->withRole(Role::Client)->create();
    $this->package = chgPaidPackage($this);
    $this->lessons = $this->package->lessons()->get();
    $this->hold = fn () => EscrowHold::query()->where('service_request_id', $this->package->id)->firstOrFail();
});

/** Pacote de 3 aulas (terça, quarta e quinta às 9h) aceite e pago. */
function chgPaidPackage(object $t): ServiceRequest
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
    test()->postJson('/api/v1/offers/'.RequestOffer::query()->where('service_request_id', $id)->value('id').'/accept')->assertOk();
    apiLogin($t->client);
    test()->postJson("/api/v1/sessions/{$id}/pay", ['method' => 'multicaixa_express'], ['Idempotency-Key' => 'chg-pay-'.$id])->assertOk();

    return ServiceRequest::query()->findOrFail($id);
}

function chgGive(object $t, ServiceRequest $lesson): void
{
    test()->travelTo($lesson->scheduled_at->copy()->subMinutes(5));
    apiLogin($t->provider->user);
    test()->postJson("/api/v1/sessions/{$lesson->id}/start")->assertOk();
    test()->travel(60)->minutes();
    $qr = test()->postJson("/api/v1/sessions/{$lesson->id}/finish")->assertOk()->json('qr');
    apiLogin($t->client);
    test()->postJson("/api/v1/sessions/{$lesson->id}/confirm", ['token' => $qr['tokens'][0]['token']], ['Idempotency-Key' => 'chg-confirm-'.$lesson->id])->assertOk();
}

/** @return list<int> */
function chgRefunds(ServiceRequest $package): array
{
    return PaymentCharge::query()->where('service_request_id', $package->id)->where('kind', 'refund')->orderBy('id')->pluck('amount')->map(fn ($a) => (int) $a)->all();
}

it('o técnico desiste a meio: as aulas que faltam voltam ao cliente e pesa a dobrar nas sanções', function () {
    chgGive($this, $this->lessons[0]);
    $this->travel(2)->hours();
    apiLogin($this->provider->user);
    $scoreBefore = ScoreEvent::query()->where('provider_profile_id', $this->provider->id)->where('type', ScoreEvent::LATE_CANCELLATION)->count();

    $this->postJson("/api/v1/packages/{$this->package->id}/cancel", ['reason' => 'Doença'], ['Idempotency-Key' => 'chg-withdraw'])->assertOk()
        ->assertJsonPath('data.state', 'paid')
        ->assertJsonPath('data.package.closed_reason', 'provider_withdrew');

    expect($this->lessons[1]->fresh())->state->toBe(RequestState::Cancelled)->closed_reason->toBe('provider_withdrew')
        ->and($this->lessons[2]->fresh()?->closed_reason)->toBe('provider_withdrew')
        ->and(($this->hold)()->amount)->toBe(0)
        ->and(($this->hold)()->state)->toBe(EscrowHold::RELEASED)
        ->and(($this->hold)()->refunded_amount)->toBe($this->lessons[1]->amount + $this->lessons[2]->amount)
        // Uma devolução por aula, cada uma paga uma só vez
        ->and(chgRefunds($this->package))->toBe([$this->lessons[1]->amount, $this->lessons[2]->amount])
        ->and(ScoreEvent::query()->where('provider_profile_id', $this->provider->id)->where('type', ScoreEvent::LATE_CANCELLATION)->count() - $scoreBefore)->toBe(2);
    Notification::assertSentTo($this->client, PackageUpdateNotification::class, fn ($n) => $n->kind === 'provider_withdrew');
});

it('depois de o técnico desistir, o cliente procura outro técnico para as aulas que faltam (uma só vez)', function () {
    chgGive($this, $this->lessons[0]);
    $this->travel(2)->hours();
    apiLogin($this->provider->user);
    $this->postJson("/api/v1/packages/{$this->package->id}/cancel", [], ['Idempotency-Key' => 'chg-withdraw-2'])->assertOk();

    apiLogin($this->client);
    $this->getJson("/api/v1/sessions/{$this->package->id}")->assertJsonPath('data.package.replaceable', true);
    $newId = $this->postJson("/api/v1/packages/{$this->package->id}/replace", [], ['Idempotency-Key' => 'chg-replace'])->assertCreated()->json('data.request_id');

    $new = ServiceRequest::query()->findOrFail($newId);
    expect($new->isPackage())->toBeTrue()
        ->and($new->sessions)->toBe(2)
        ->and($new->republished_from_id)->toBe($this->package->id)
        ->and(array_map(fn ($d) => Carbon::parse($d)->timestamp, $new->lesson_schedule ?? []))
        ->toBe([$this->lessons[1]->scheduled_at->timestamp, $this->lessons[2]->scheduled_at->timestamp]);

    $this->postJson("/api/v1/packages/{$this->package->id}/replace", [], ['Idempotency-Key' => 'chg-replace-2'])->assertCreated()->assertJsonPath('data.request_id', $newId);
    $this->getJson("/api/v1/sessions/{$this->package->id}")->assertJsonPath('data.package.replaceable', false);
});

it('o cliente desiste com mais de 24 h de aviso: tudo devolvido e o pacote fica cancelado', function () {
    apiLogin($this->client);
    $this->postJson("/api/v1/packages/{$this->package->id}/cancel", [], ['Idempotency-Key' => 'chg-client-cancel'])->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect(($this->hold)()->state)->toBe(EscrowHold::REFUNDED)
        ->and(($this->hold)()->refunded_amount)->toBe($this->package->amount)
        ->and(chgRefunds($this->package))->toHaveCount(3);
});

it('o cliente desiste a menos de 24 h da próxima aula: essa conta como dada', function () {
    $this->travelTo($this->lessons[0]->scheduled_at->copy()->subHours(20));
    apiLogin($this->client);
    $this->postJson("/api/v1/packages/{$this->package->id}/cancel", [], ['Idempotency-Key' => 'chg-client-late'])->assertOk()
        ->assertJsonPath('data.state', 'paid');

    expect($this->lessons[0]->fresh()?->closed_reason)->toBe('client_cancelled_late')
        ->and(($this->hold)()->refunded_amount)->toBe($this->lessons[1]->amount + $this->lessons[2]->amount);
    apiLogin($this->provider->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', $this->lessons[0]->provider_net);
});

it('remarca uma aula com acordo, até duas vezes por pacote, com antecedência e sem conflitos', function () {
    $second = $this->lessons[1];
    $newTime = $second->scheduled_at->copy()->setTime(15, 0);

    apiLogin($this->client);
    $this->getJson("/api/v1/sessions/{$second->id}")->assertJsonPath('data.lesson.reschedules_left', 2)->assertJsonPath('data.lesson.sequence', 2);
    $this->postJson("/api/v1/sessions/{$second->id}/reschedule", ['scheduled_at' => $newTime->toIso8601String()])->assertOk()
        ->assertJsonPath('data.lesson.reschedule_by_me', true);
    // Quem propôs não aceita a própria proposta
    $this->postJson("/api/v1/sessions/{$second->id}/reschedule/accept")->assertStatus(409);

    apiLogin($this->provider->user);
    $this->getJson("/api/v1/sessions/{$second->id}")->assertJsonPath('data.actions', fn ($a) => in_array('answer_reschedule', $a, true));
    $this->postJson("/api/v1/sessions/{$second->id}/reschedule/accept")->assertOk();
    expect($second->fresh()?->scheduled_at->equalTo($newTime))->toBeTrue()
        ->and($this->package->fresh()?->reschedules_used)->toBe(1);

    // Para cima da aula remarcada: conflito
    $third = $this->lessons[2];
    $this->postJson("/api/v1/sessions/{$third->id}/reschedule", ['scheduled_at' => $newTime->copy()->addMinutes(30)->toIso8601String()])
        ->assertStatus(409)->assertJsonPath('error.code', 'schedule_conflict');

    // Recusar mantém a hora e não gasta remarcação
    $this->postJson("/api/v1/sessions/{$third->id}/reschedule", ['scheduled_at' => $third->scheduled_at->copy()->setTime(17, 0)->toIso8601String()])->assertOk();
    apiLogin($this->client);
    $this->postJson("/api/v1/sessions/{$third->id}/reschedule/reject")->assertOk();
    expect($third->fresh()?->scheduled_at->equalTo($third->scheduled_at))->toBeTrue()
        ->and($this->package->fresh()?->reschedules_used)->toBe(1);

    // Segunda e última remarcação; a terceira já não é permitida
    $this->postJson("/api/v1/sessions/{$third->id}/reschedule", ['scheduled_at' => $third->scheduled_at->copy()->setTime(18, 0)->toIso8601String()])->assertOk();
    apiLogin($this->provider->user);
    $this->postJson("/api/v1/sessions/{$third->id}/reschedule/accept")->assertOk();
    $this->postJson("/api/v1/sessions/{$this->lessons[0]->id}/reschedule", ['scheduled_at' => $this->lessons[0]->scheduled_at->copy()->setTime(19, 0)->toIso8601String()])
        ->assertStatus(409)->assertJsonPath('error.code', 'reschedule_not_allowed');
});

it('não remarca com menos de 12 h de aviso', function () {
    $this->travelTo($this->lessons[0]->scheduled_at->copy()->subHours(10));
    apiLogin($this->client);

    $this->postJson("/api/v1/sessions/{$this->lessons[0]->id}/reschedule", ['scheduled_at' => $this->lessons[0]->scheduled_at->copy()->addDays(1)->setTime(17, 0)->toIso8601String()])
        ->assertStatus(409)->assertJsonPath('error.code', 'reschedule_not_allowed');
});

it('falta do técnico numa aula: essa aula volta ao cliente a partir do pacote', function () {
    $first = $this->lessons[0];
    $first->forceFill(['state' => RequestState::Cancelled->value, 'closed_reason' => 'no_show_provider', 'no_show_contest_until' => now()->subMinute()])->save();

    app(SessionTimeoutRules::class)->settleUncontestedNoShows();

    expect(($this->hold)()->amount)->toBe($this->package->amount - $first->amount)
        ->and(chgRefunds($this->package))->toBe([$first->amount])
        ->and($this->package->fresh()?->state)->toBe(RequestState::Accepted);
});
