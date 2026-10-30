<?php

declare(strict_types=1);

use App\Modules\Notifications\Channels\SmsChannel;
use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Notifications\Notifications\SessionUpdateNotification as Update;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Services\AddendumFlow;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\MarkOnTheWay;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/*
| BISCA, Fase 3.7: avisos de cada passo do serviço: novo pedido, pedido aceite, técnico a caminho, orçamento enviado
| e aceite, pedido concluído, pagamento e disputa. Sem push ativo, os urgentes vão por SMS.
*/

beforeEach(function () {
    Notification::fake();
});

function kindsSentTo(User $user): array
{
    return Notification::sent($user, Update::class)->map(fn (Update $n) => $n->kind)->all();
}

it('novo pedido e pedido aceite', function () {
    $cleaning = Category::query()->where('slug', 'limpeza')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, 500)->withCategories($cleaning)->create();
    $client = User::factory()->withRole(Role::Client)->create();
    $this->travelTo(now()->setTime(8, 0));

    apiLogin($client);
    $this->postJson('/api/v1/service-requests', [
        'category_id' => $cleaning->id, 'answers' => ['descricao' => 'Casa toda', 'tipologia' => 'T2'],
        'scheduled_at' => now()->setTime(14, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated();
    Notification::assertSentTo($provider->user, NewOfferNotification::class);

    apiLogin($provider->user);
    $this->postJson('/api/v1/offers/'.RequestOffer::query()->sole()->id.'/accept')->assertOk();
    Notification::assertSentTo($client, RequestOutcomeNotification::class, fn ($n) => $n->outcome === RequestOutcomeNotification::MATCHED);
});

it('a caminho, orçamento enviado e aceite, pagamento, adenda, concluído e disputa', function () {
    $ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
    $session = acceptedSession('electronic', ['category_id' => $ac->id]);
    $session->forceFill(['pricing_mode' => PricingMode::Quote, 'amount' => 0, 'commission' => 0, 'provider_net' => 0, 'payment_status' => null, 'payment_due_at' => null])->save();
    $provider = $session->provider->user;
    $client = $session->client;

    app(MarkOnTheWay::class)->handle($session, $provider);
    $flow = app(QuoteFlow::class);
    $flow->startDiagnosis($session, $provider);
    $quote = $flow->submit($session, $provider, ['labour_amount' => 30000, 'execution_days' => 1]);
    $flow->accept($quote, $client);
    app(PayForSession::class)->handle($session->fresh() ?? $session, $client, 'multicaixa_express');
    app(StartSession::class)->handle($session->fresh() ?? $session, $provider);
    $addendum = app(AddendumFlow::class)->submit($session, $provider, 'Trocar o filtro', 1000);
    app(AddendumFlow::class)->clientApprove($addendum, $client);
    app(FinishSession::class)->handle($session->fresh() ?? $session, $provider);
    app(OpenDispute::class)->handle($session->fresh() ?? $session, $client, 'lesson_not_happened', 'O trabalho ficou por acabar.');

    expect(kindsSentTo($client))->toContain(Update::ON_THE_WAY, Update::QUOTE_SENT, Update::STARTED, Update::ADDENDUM_PROPOSED, Update::QR_READY)
        ->and(kindsSentTo($provider))->toContain(Update::QUOTE_ACCEPTED, Update::PAYMENT_RECEIVED, Update::ADDENDUM_DECIDED, Update::DISPUTE_OPENED);
});

it('sem push ativo, os urgentes vão por SMS; os outros ficam só na app', function () {
    $session = acceptedSession('cash');
    $user = $session->client;

    expect((new Update($session, Update::ON_THE_WAY))->via($user))->toBe(['database', SmsChannel::class])
        ->and((new Update($session, Update::QUOTE_SENT, '5000'))->via($user))->toBe(['database', SmsChannel::class])
        ->and((new Update($session, Update::STARTED))->via($user))->toBe(['database'])
        ->and((new Update($session, Update::QUOTE_SENT, '35000'))->toSms($user))->toStartWith('BISCA: Orçamento recebido')->toContain('35.000');

    $user->pushSubscriptions()->create(['endpoint' => 'https://push.example/x', 'public_key' => 'k', 'auth_token' => 't']);
    expect((new Update($session, Update::ON_THE_WAY))->via($user->fresh() ?? $user))->toBe(['database', WebPushChannel::class]);
});
