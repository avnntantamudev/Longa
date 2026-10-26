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
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
});

function finishByQr(TrainingSession $session): void
{
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->trainer->user);
    $token = app(FinishSession::class)->handle($session, $session->trainer->user)['qr']['tokens'][0]['token'];
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->student, $token, null);
}

it('ganhos por mês: carteira, em mão e comissões, com exportação', function () {
    $electronic = paidSession();
    finishByQr($electronic);
    $cash = acceptedSession('cash', ['trainer_profile_id' => $electronic->trainer_profile_id, 'scheduled_at' => now()->addHours(4)]);
    finishByQr($cash);
    app(ConfirmCashPayment::class)->handle($cash->fresh() ?? $cash, $cash->student);

    apiLogin($electronic->trainer->user);
    $data = $this->getJson('/api/v1/me/reports/earnings?year='.now()->year)->assertOk()->json('data');

    expect($data['totals'])->toBe(['lessons' => 2, 'cash_lessons' => 1, 'credited' => 21250, 'commissions' => 7500, 'cash_received' => 25000]);

    $this->get('/api/v1/me/reports/earnings/export?format=pdf&year='.now()->year)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/api/v1/me/reports/earnings/export?format=csv&year='.now()->year)->assertOk()->assertDownload('longa-ganhos-'.now()->year.'.csv');
});

it('cada formador só vê os seus ganhos; alunos não têm', function () {
    $session = paidSession();
    finishByQr($session);

    apiLogin(App\Modules\Users\Models\TrainerProfile::factory()->verified()->create()->user);
    $this->getJson('/api/v1/me/reports/earnings')->assertJsonPath('data.totals.credited', 0);

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->getJson('/api/v1/me/reports/earnings')->assertForbidden();
});
