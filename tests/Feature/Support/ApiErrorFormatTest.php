<?php

declare(strict_types=1);

use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('/validation', fn () => request()->validate(['phone' => ['required']]));
        Route::get('/domain', fn () => throw new ApiException('otp_locked', 'Demasiadas tentativas.', 429, ['retry_after' => 60]));
        Route::get('/crash', fn () => throw new RuntimeException('segredo interno'));
        Route::get('/auth', fn () => 'ok')->middleware('auth:sanctum');
    });
});

it('devolve erros de validação no formato único', function () {
    $this->postJson('/api/v1/_test/validation')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'Os dados enviados são inválidos.')
        ->assertJsonStructure(['error' => ['details' => ['phone']], 'request_id']);
});

it('devolve 404 sem expor detalhes internos', function () {
    $response = $this->getJson('/api/v1/nao-existe')->assertNotFound();

    expect($response->json())->toBe([
        'error' => ['code' => 'not_found', 'message' => 'O recurso pedido não existe.'],
        'request_id' => $response->headers->get('X-Request-Id'),
    ]);
});

it('devolve exceções de domínio com o seu código e detalhes', function () {
    $this->getJson('/api/v1/_test/domain')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'otp_locked')
        ->assertJsonPath('error.details.retry_after', 60);
});

it('esconde a mensagem de erros inesperados fora do modo debug', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/crash')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertDontSee('segredo interno');
});

it('devolve 401 quando falta autenticação', function () {
    $this->getJson('/api/v1/_test/auth')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});
