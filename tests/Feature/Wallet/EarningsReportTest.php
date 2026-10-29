<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
});

function finishByQr(ServiceRequest $session): void
{
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    $token = app(FinishSession::class)->handle($session, $session->provider->user)['qr']['tokens'][0]['token'];
    app(ConfirmSession::class)->handle($session->fresh() ?? $session, $session->client, $token, null);
}

it('ganhos por mês: carteira, em mão e comissões, com exportação', function () {
    $electronic = paidSession();
    finishByQr($electronic);
    $cash = acceptedSession('cash', ['provider_profile_id' => $electronic->provider_profile_id, 'scheduled_at' => now()->addHours(4)]);
    finishByQr($cash);
    app(ConfirmCashPayment::class)->handle($cash->fresh() ?? $cash, $cash->client);

    apiLogin($electronic->provider->user);
    $data = $this->getJson('/api/v1/me/reports/earnings?year='.now()->year)->assertOk()->json('data');

    expect($data['totals'])->toBe(['lessons' => 2, 'cash_lessons' => 1, 'credited' => 21250, 'commissions' => 7500, 'cash_received' => 25000]);

    $this->get('/api/v1/me/reports/earnings/export?format=pdf&year='.now()->year)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/api/v1/me/reports/earnings/export?format=csv&year='.now()->year)->assertOk()->assertDownload('longa-ganhos-'.now()->year.'.csv');
});

it('cada técnico só vê os seus ganhos; clientes não têm', function () {
    $session = paidSession();
    finishByQr($session);

    apiLogin(App\Modules\Users\Models\ProviderProfile::factory()->verified()->create()->user);
    $this->getJson('/api/v1/me/reports/earnings')->assertJsonPath('data.totals.credited', 0);

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson('/api/v1/me/reports/earnings')->assertForbidden();
});
