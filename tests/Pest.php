<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Testes de Feature usam a aplicação completa e uma base de dados limpa por teste.
| Testes de Unit não arrancam o Laravel.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Inicia uma sessão API completa (token de acesso + AuthSession) para o utilizador.
 */
function apiLogin(App\Modules\Users\Models\User $user, bool $pinVerified = true): App\Modules\Users\Models\User
{
    app('auth')->forgetGuards();
    $tokens = app(App\Modules\Auth\Services\AuthSessionService::class)->startApi(request(), $user, 'Teste');
    test()->withToken($tokens->accessToken);
    // Como uma app que acabou de pedir o PIN (pagamentos exigem PIN recente)
    if ($pinVerified) {
        app(App\Modules\Auth\Services\PinService::class)->markRecent($tokens->sessionId);
    }

    return $user;
}

/**
 * Inicia uma sessão web (PWA) com AuthSession, como faria o login por OTP.
 */
function loginWeb(App\Modules\Users\Models\User $user): App\Modules\Users\Models\User
{
    // Utilizador com PIN e sessão acabada de desbloquear (como depois de introduzir o PIN)
    if ($user->pin_hash === null) {
        $user->forceFill(['pin_hash' => Illuminate\Support\Facades\Hash::make('482916'), 'pin_set_at' => now()])->save();
    }
    test()->actingAs($user);
    $session = App\Modules\Auth\Models\AuthSession::query()->create(['user_id' => $user->id, 'type' => 'web', 'last_active_at' => now()]);
    test()->withSession([
        App\Modules\Auth\Services\AuthSessionService::SESSION_KEY => $session->id,
        App\Modules\Auth\Http\Middleware\EnsurePinUnlocked::UNLOCKED_AT => now()->getTimestamp(),
        App\Modules\Auth\Http\Middleware\EnsurePinUnlocked::LAST_SEEN => now()->getTimestamp(),
    ]);

    return $user;
}

/*
| Testes de concorrência: dados gravados de verdade (outros processos têm de os ver).
*/
pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseTruncation::class)
    ->in('Concurrency');

/**
 * Aula acabada de aceitar (como faria o TRIM TRIM): eletrónica em pending_payment, dinheiro em scheduled.
 *
 * @param  array<string, mixed>  $overrides
 */
function acceptedSession(string $paymentMethod = 'electronic', array $overrides = []): App\Modules\Sessions\Models\ServiceJob
{
    $request = App\Modules\Requests\Models\ServiceRequest::factory()->create(['payment_method' => $paymentMethod]);
    $provider = App\Modules\Users\Models\ProviderProfile::factory()->verified()->create();
    $electronic = $paymentMethod === 'electronic';

    $session = App\Modules\Sessions\Models\ServiceJob::query()->create([
        'service_request_id' => $request->id, 'client_id' => $request->client_id, 'provider_profile_id' => $provider->id,
        'category_id' => $request->category_id, 'title' => 'Aula de SGME', 'amount' => 25000, 'commission' => 3750, 'provider_net' => 21250,
        'scheduled_at' => now()->addDay()->setTime(14, 0), 'duration_minutes' => 60, 'payment_method' => $paymentMethod,
        'payment_due_at' => $electronic ? now()->addMinutes(10) : null,
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => -8.9167, 'longitude' => 13.1833,
        ...$overrides,
    ]);
    $session->forceFill(['state' => $electronic ? 'pending_payment' : 'scheduled'])->save();
    App\Modules\Sessions\Models\Conversation::query()->create(['service_job_id' => $session->id]);

    return $session->fresh() ?? $session;
}

/**
 * Aula eletrónica já paga (dinheiro em escrow) e agendada.
 *
 * @param  array<string, mixed>  $overrides
 */
function paidSession(array $overrides = []): App\Modules\Sessions\Models\ServiceJob
{
    $session = acceptedSession('electronic', $overrides);
    app(App\Modules\Payments\Actions\PayForSession::class)->handle($session, $session->client, 'multicaixa_express');

    return $session->fresh() ?? $session;
}

/**
 * Saldos do ledger relevantes para uma aula (cache e fonte de verdade têm de coincidir).
 *
 * @return array{escrow: int, provider: int, revenue: int, refunds: int, funds_in: int}
 */
function balances(App\Modules\Sessions\Models\ServiceJob $session): array
{
    $ledger = app(App\Modules\Wallet\Ledger\LedgerService::class);
    $codes = [
        'escrow' => App\Modules\Wallet\Ledger\Accounts::ESCROW,
        'provider' => App\Modules\Wallet\Ledger\Accounts::providerAvailable($session->provider_profile_id),
        'revenue' => App\Modules\Wallet\Ledger\Accounts::PLATFORM_REVENUE,
        'refunds' => App\Modules\Wallet\Ledger\Accounts::REFUNDS_PAYABLE,
        'funds_in' => App\Modules\Wallet\Ledger\Accounts::FUNDS_IN,
    ];

    $result = [];
    foreach ($codes as $name => $code) {
        expect($ledger->balance($code))->toBe($ledger->balanceFromEntries($code));
        $result[$name] = $ledger->balance($code);
    }
    expect((int) App\Modules\Wallet\Models\LedgerEntry::query()->sum('amount'))->toBe(0);

    return $result;
}
