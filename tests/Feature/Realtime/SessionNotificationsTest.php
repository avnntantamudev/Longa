<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Sessions\Actions\CancelSession;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Events\DisputeOpened;
use App\Modules\Sessions\Events\DisputeResolved;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Events\SessionCancelled;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
});

function notified(User $user, string $kind): bool
{
    return Notification::sent($user, SessionUpdateNotification::class)->contains(fn (SessionUpdateNotification $n) => $n->kind === $kind);
}

it('fluxo QR: cliente avisado do início e do QR; técnico avisado da confirmação com o valor', function () {
    $session = paidSession();
    $provider = $session->provider->user;
    $this->travelTo($session->scheduled_at);

    app(StartSession::class)->handle($session, $provider);
    $token = app(FinishSession::class)->handle($session, $provider)['qr']['tokens'][0]['token'];
    app(FinishSession::class)->handle($session->fresh() ?? $session, $provider); // renovação do lote: sem novo aviso
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->client, $token, null);

    expect(notified($session->client, 'started'))->toBeTrue()
        ->and(Notification::sent($session->client, SessionUpdateNotification::class)->filter(fn ($n) => $n->kind === 'qr_ready'))->toHaveCount(1)
        ->and(notified($provider, 'confirmed'))->toBeTrue()
        ->and(notified($provider, 'started'))->toBeFalse();

    // O técnico é avisado do pagamento e, no fim, da confirmação com o valor
    expect(notified($provider, 'payment_received'))->toBeTrue();
    $push = Notification::sent($provider, SessionUpdateNotification::class)->first(fn ($n) => $n->kind === 'confirmed');
    expect($push->toArray($provider)['body'])->toContain('Kz 21.250 disponíveis na sua carteira');
});

it('cancelamento avisa a outra parte com o motivo', function () {
    $session = paidSession();
    app(CancelSession::class)->handle($session, $session->provider->user, 'Doença');

    $sent = Notification::sent($session->client, SessionUpdateNotification::class)->first();
    expect($sent?->kind)->toBe('cancelled')
        ->and($sent?->toArray($session->client)['body'])->toContain('Motivo: Doença');
    expect(notified($session->provider->user, 'cancelled'))->toBeFalse();
});

it('disputa aberta avisa a outra parte; decisão avisa ambos', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session, $session->provider->user);
    $dispute = app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->client, 'quality', 'O serviço ficou aquém do combinado.');

    expect(notified($session->provider->user, 'dispute_opened'))->toBeTrue()
        ->and(notified($session->client, 'dispute_opened'))->toBeFalse();

    app(ResolveDispute::class)->handle($dispute, User::factory()->withRole(Role::Admin)->create(), 'refunded', null, 'Sem evidências do serviço.');

    expect(notified($session->provider->user, 'dispute_resolved'))->toBeTrue()
        ->and(notified($session->client, 'dispute_resolved'))->toBeTrue();
});

it('confirmação automática (R2) também avisa o técnico', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    $this->travelTo($session->ends_at?->copy()->addHours(3));
    $this->artisan('longa:sessions:tick');
    $this->travel(49)->hours();
    $this->artisan('longa:sessions:tick');

    expect(notified($session->provider->user, 'confirmed'))->toBeTrue();
});

it('eventos em tempo real vão para canais privados e nunca levam tokens de QR', function () {
    $session = paidSession();
    $dispute = new App\Modules\Sessions\Models\Dispute(['service_request_id' => $session->id]);

    $events = [
        new SessionStarted($session), new QrIssued($session), new SessionConfirmed($session),
        new SessionCancelled($session), new DisputeOpened($session), new DisputeResolved($session, $dispute),
    ];
    foreach ($events as $event) {
        expect($event->broadcastOn()->name)->toBe("private-session.{$session->id}")
            ->and(array_keys($event->broadcastWith()))->not->toContain('token', 'tokens', 'code');
    }

    $wallet = new WalletUpdated($session->provider->user_id, 'test', $session->id);
    expect($wallet->broadcastOn()->name)->toBe("private-user.{$session->provider->user_id}");
});
