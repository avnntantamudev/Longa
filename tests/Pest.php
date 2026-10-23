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
