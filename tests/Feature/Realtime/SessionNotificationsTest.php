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

it('fluxo QR: aluno avisado do início e do QR; formador avisado da confirmação com o valor', function () {
    $session = paidSession();
    $trainer = $session->trainer->user;
    $this->travelTo($session->scheduled_at);

    app(StartSession::class)->handle($session, $trainer);
    $token = app(FinishSession::class)->handle($session, $trainer)['qr']['tokens'][0]['token'];
    app(FinishSession::class)->handle($session->fresh() ?? $session, $trainer); // renovação do lote: sem novo aviso
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->student, $token, null);

    expect(notified($session->student, 'started'))->toBeTrue()
        ->and(Notification::sent($session->student, SessionUpdateNotification::class)->filter(fn ($n) => $n->kind === 'qr_ready'))->toHaveCount(1)
        ->and(notified($trainer, 'confirmed'))->toBeTrue()
        ->and(notified($trainer, 'started'))->toBeFalse();

    $push = Notification::sent($trainer, SessionUpdateNotification::class)->first();
    expect($push->toArray($trainer)['body'])->toContain('Kz 21.250 disponíveis na sua carteira');
});

it('cancelamento avisa a outra parte com o motivo', function () {
    $session = paidSession();
    app(CancelSession::class)->handle($session, $session->trainer->user, 'Doença');

    $sent = Notification::sent($session->student, SessionUpdateNotification::class)->first();
    expect($sent?->kind)->toBe('cancelled')
        ->and($sent?->toArray($session->student)['body'])->toContain('Motivo: Doença');
    Notification::assertNotSentTo($session->trainer->user, SessionUpdateNotification::class);
});

it('disputa aberta avisa a outra parte; decisão avisa ambos', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->trainer->user);
    app(FinishSession::class)->handle($session, $session->trainer->user);
    $dispute = app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->student, 'quality', 'A aula ficou aquém do combinado.');

    expect(notified($session->trainer->user, 'dispute_opened'))->toBeTrue()
        ->and(notified($session->student, 'dispute_opened'))->toBeFalse();

    app(ResolveDispute::class)->handle($dispute, User::factory()->withRole(Role::Admin)->create(), 'refunded', null, 'Sem evidências da aula.');

    expect(notified($session->trainer->user, 'dispute_resolved'))->toBeTrue()
        ->and(notified($session->student, 'dispute_resolved'))->toBeTrue();
});

it('confirmação automática (R2) também avisa o formador', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->trainer->user);
    $this->travelTo($session->ends_at?->copy()->addHours(3));
    $this->artisan('longa:sessions:tick');
    $this->travel(49)->hours();
    $this->artisan('longa:sessions:tick');

    expect(notified($session->trainer->user, 'confirmed'))->toBeTrue();
});

it('eventos em tempo real vão para canais privados e nunca levam tokens de QR', function () {
    $session = paidSession();
    $dispute = new App\Modules\Sessions\Models\Dispute(['training_session_id' => $session->id]);

    $events = [
        new SessionStarted($session), new QrIssued($session), new SessionConfirmed($session),
        new SessionCancelled($session), new DisputeOpened($session), new DisputeResolved($session, $dispute),
    ];
    foreach ($events as $event) {
        expect($event->broadcastOn()->name)->toBe("private-session.{$session->id}")
            ->and(array_keys($event->broadcastWith()))->not->toContain('token', 'tokens', 'code');
    }

    $wallet = new WalletUpdated($session->trainer->user_id, 'test', $session->id);
    expect($wallet->broadcastOn()->name)->toBe("private-user.{$session->trainer->user_id}");
});
