<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function completeWithQr(TrainingSession $session): void
{
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->trainer->user);
    $token = app(FinishSession::class)->handle($session, $session->trainer->user)['qr']['tokens'][0]['token'];
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->student, $token, null);
}

it('carteira vazia de um formador novo', function () {
    $trainer = App\Modules\Users\Models\TrainerProfile::factory()->verified()->create();
    apiLogin($trainer->user);

    $this->getJson('/api/v1/wallet')->assertOk()
        ->assertExactJson(['data' => ['available' => 0, 'pending' => 0, 'total_earned' => 0, 'commission_due' => 0, 'currency' => 'AOA']]);
    $this->getJson('/api/v1/wallet/movements')->assertOk()->assertJsonCount(0, 'data');
});

it('pendente enquanto retido, disponível depois da confirmação', function () {
    $session = paidSession();
    apiLogin($session->trainer->user);

    $this->getJson('/api/v1/wallet')->assertJsonPath('data.pending', 21250)->assertJsonPath('data.available', 0);

    completeWithQr($session);
    apiLogin($session->trainer->user);

    $this->getJson('/api/v1/wallet')->assertJsonPath('data.pending', 0)
        ->assertJsonPath('data.available', 21250)
        ->assertJsonPath('data.total_earned', 21250);
    $this->getJson('/api/v1/wallet/movements')->assertOk()
        ->assertJsonPath('data.0.type', 'escrow_release')
        ->assertJsonPath('data.0.label', 'Aula confirmada')
        ->assertJsonPath('data.0.amount', 21250)
        ->assertJsonPath('data.0.session_id', $session->id);
});

it('comissão de aula em mão é descontada na aula eletrónica seguinte', function () {
    $cash = acceptedSession('cash');
    completeWithQr($cash);
    app(ConfirmCashPayment::class)->handle($cash->fresh() ?? $cash, $cash->student);

    apiLogin($cash->trainer->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.commission_due', 3750);

    $electronic = paidSession(['trainer_profile_id' => $cash->trainer_profile_id, 'scheduled_at' => now()->addDays(2)]);
    completeWithQr($electronic);

    apiLogin($cash->trainer->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.commission_due', 0)
        ->assertJsonPath('data.available', 21250 - 3750);
    expect(collect($this->getJson('/api/v1/wallet/movements')->json('data'))->pluck('type')->all())
        ->toContain('commission_offset', 'cash_commission_due', 'escrow_release');
});

it('só formadores têm carteira e cada um só vê a sua', function () {
    $session = paidSession();
    completeWithQr($session);

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->getJson('/api/v1/wallet')->assertForbidden();

    apiLogin(App\Modules\Users\Models\TrainerProfile::factory()->verified()->create()->user);
    $this->getJson('/api/v1/wallet')->assertJsonPath('data.available', 0);
});

it('a carteira exige autenticação', function () {
    $this->getJson('/api/v1/wallet')->assertUnauthorized();
});

it('linha do tempo e ações disponíveis da aula', function () {
    $session = paidSession();
    apiLogin($session->student);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['cancel'])
        ->assertJsonPath('data.state_label', 'Agendada');

    $this->travelTo($session->scheduled_at);
    apiLogin($session->trainer->user);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['start', 'cancel']);
    app(StartSession::class)->handle($session, $session->trainer->user);
    app(FinishSession::class)->handle($session, $session->trainer->user);

    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['show_qr']);
    apiLogin($session->student);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.actions', ['confirm', 'confirm_without_qr', 'dispute']);

    $types = collect($this->getJson("/api/v1/sessions/{$session->id}/events")->assertOk()->json('data'))->pluck('type')->all();
    expect($types)->toContain('payment_confirmed', 'started', 'finished', 'qr_issued');

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->getJson("/api/v1/sessions/{$session->id}/events")->assertForbidden();
});
