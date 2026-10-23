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
function apiLogin(App\Modules\Users\Models\User $user): App\Modules\Users\Models\User
{
    app('auth')->forgetGuards();
    $tokens = app(App\Modules\Auth\Services\AuthSessionService::class)->startApi(request(), $user, 'Teste');
    test()->withToken($tokens->accessToken);

    return $user;
}

/**
 * Inicia uma sessão web (PWA) com AuthSession, como faria o login por OTP.
 */
function loginWeb(App\Modules\Users\Models\User $user): App\Modules\Users\Models\User
{
    test()->actingAs($user);
    $session = App\Modules\Auth\Models\AuthSession::query()->create(['user_id' => $user->id, 'type' => 'web', 'last_active_at' => now()]);
    test()->withSession([App\Modules\Auth\Services\AuthSessionService::SESSION_KEY => $session->id]);

    return $user;
}

/*
| Testes de concorrência: dados gravados de verdade (outros processos têm de os ver).
*/
pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseTruncation::class)
    ->in('Concurrency');
