<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Notifications\Channels\SmsChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Notifications\Push\PushReportHandler;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(function () {
    $this->sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $this->trainer = TrainerProfile::factory()->verified()->near($lat, $lng, 1200)->withSkills($this->sgme)->create();
    $this->request = TrainingRequest::factory()->forSkill($this->sgme)->create();
});

function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): PushSubscription
{
    /** @var PushSubscription */
    return $user->updatePushSubscription($endpoint, 'BPublicKey', 'authToken', 'aes128gcm');
}

it('regista e remove a subscrição Web Push do dispositivo', function () {
    $user = apiLogin(User::factory()->create());
    $endpoint = 'https://fcm.googleapis.com/fcm/send/xyz';

    $this->postJson('/api/v1/push-subscriptions', [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BKey', 'auth' => 'secret'],
        'device' => 'Chrome · Android',
    ])->assertCreated();

    expect($user->pushSubscriptions()->sole()->device)->toBe('Chrome · Android');

    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => $endpoint])->assertNoContent();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('rejeita endpoints que não são HTTPS', function () {
    apiLogin(User::factory()->create());

    $this->postJson('/api/v1/push-subscriptions', ['endpoint' => 'http://inseguro', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
        ->assertStatus(422);
});

it('envia a oferta por push, em fila, quando o formador tem subscrição', function () {
    Notification::fake();
    subscribe($this->trainer->user);

    app(MatchingCoordinator::class)->start($this->request);

    Notification::assertSentTo($this->trainer->user, NewOfferNotification::class, function (NewOfferNotification $n, array $channels) {
        return $channels === ['database', WebPushChannel::class] && $n->queue === 'notifications';
    });
});

it('usa SMS como fallback sem push ativo, com limite por hora', function () {
    config(['longa.matching.sms_fallback_per_trainer_per_hour' => 1]);
    $offer = RequestOffer::query()->create([
        'training_request_id' => $this->request->id, 'trainer_profile_id' => $this->trainer->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 1234, 'score_snapshot' => 50, 'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);

    $first = new NewOfferNotification($offer);
    $second = new NewOfferNotification($offer);

    expect($first->via($this->trainer->user))->toBe(['database', SmsChannel::class])
        ->and($second->via($this->trainer->user))->toBe(['database']);
});

it('escreve a oferta em texto curto: "Novo pedido a 1,2 km: SGME 25.000 Kz"', function () {
    $offer = RequestOffer::query()->create([
        'training_request_id' => $this->request->id, 'trainer_profile_id' => $this->trainer->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 1234, 'score_snapshot' => 50, 'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);
    $notification = new NewOfferNotification($offer);

    $push = $notification->toWebPush($this->trainer->user, $notification)->toArray();
    $sms = $notification->toSms($this->trainer->user);

    expect($push['title'])->toBe('TRIM TRIM · Novo pedido a 1,2 km: SGME — Gestão Empresarial 25.000 Kz')
        ->and($push['data']['url'])->toBe("/formador/ofertas/{$offer->id}")
        ->and($sms)->toStartWith('BISCA: Novo pedido a 1,2 km')
        ->and($sms)->not->toContain((string) $this->request->latitude);

    // O push não deve chegar depois de a oferta expirar
    $options = $notification->toWebPush($this->trainer->user, $notification)->getOptions();
    expect($options['TTL'])->toBeLessThanOrEqual(60)->and($options['urgency'])->toBe('high');
});

it('não envia a oferta se ela já fechou quando o job corre', function () {
    $offer = RequestOffer::query()->create([
        'training_request_id' => $this->request->id, 'trainer_profile_id' => $this->trainer->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 100, 'score_snapshot' => 50, 'sent_at' => now(), 'expires_at' => now()->subSecond(),
    ]);
    $notification = new NewOfferNotification($offer);

    expect($notification->shouldSend($this->trainer->user, WebPushChannel::class))->toBeFalse()
        ->and($notification->shouldSend($this->trainer->user, 'database'))->toBeTrue();
});

it('apaga subscrições inválidas (410) e atualiza o último uso das válidas', function () {
    $valid = subscribe($this->trainer->user, 'https://push.example/valid');
    $gone = subscribe($this->trainer->user, 'https://push.example/gone');
    $handler = new PushReportHandler;
    $message = new WebPushMessage;

    $handler->handleReport(new MessageSentReport(new Psr7Request('POST', $valid->endpoint), new Psr7Response(201), true), $valid, $message);
    $handler->handleReport(new MessageSentReport(new Psr7Request('POST', $gone->endpoint), new Psr7Response(410), false, 'Gone'), $gone, $message);

    expect($valid->fresh()?->last_used_at)->not->toBeNull()
        ->and(PushSubscription::query()->whereKey($gone->id)->exists())->toBeFalse();
});

it('avisa o aluno quando não há formadores, com ligação para republicar', function () {
    Notification::fake();
    $empty = TrainingRequest::factory()->forSkill(Skill::query()->where('slug', 'frances')->firstOrFail())->create();

    app(MatchingCoordinator::class)->start($empty);

    Notification::assertSentTo($empty->student, RequestOutcomeNotification::class, fn ($n) => $n->outcome === 'expired');
});

it('lista e marca notificações na app como lidas', function () {
    $user = apiLogin($this->request->student);
    $user->notify(new RequestOutcomeNotification($this->request, RequestOutcomeNotification::EXPIRED));

    $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 1);
    $id = $response->json('data.0.id');
    expect($response->json('data.0.title'))->toBe('Sem formadores disponíveis');

    $this->postJson("/api/v1/notifications/{$id}/read")->assertNoContent();
    $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
});
