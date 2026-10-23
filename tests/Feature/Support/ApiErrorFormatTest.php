<?php

declare(strict_types=1);

use App\Support\Api\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('/validation', fn () => request()->validate(['phone' => ['required']]));
        Route::get('/domain', fn () => throw new ApiException('otp_locked', 'Demasiadas tentativas.', 429, ['retry_after' => 60]));
        Route::get('/crash', fn () => throw new RuntimeException('segredo interno'));
        Route::get('/auth', fn () => 'ok')->middleware('auth:sanctum');
        Route::get('/forbidden', fn () => throw new AuthorizationException);
        Route::get('/expired', fn () => throw new TokenMismatchException);
        Route::get('/throttled', fn () => 'ok')->middleware('throttle:1,1');
        Route::get('/unavailable', fn () => abort(503));
        Route::get('/teapot', fn () => abort(418));
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

it('inclui detalhes da exceção apenas em modo debug', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/_test/crash')
        ->assertStatus(500)
        ->assertJsonPath('error.details.exception', RuntimeException::class);
});

it('devolve 401 quando falta autenticação', function () {
    $this->getJson('/api/v1/_test/auth')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('devolve 403 quando a ação não é permitida', function () {
    $this->getJson('/api/v1/_test/forbidden')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('devolve 405 para métodos não suportados', function () {
    $this->deleteJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('devolve 419 quando a sessão expirou', function () {
    $this->getJson('/api/v1/_test/expired')
        ->assertStatus(419)
        ->assertJsonPath('error.code', 'session_expired');
});

it('devolve 429 com Retry-After quando há demasiados pedidos', function () {
    $this->getJson('/api/v1/_test/throttled')->assertOk();

    $this->getJson('/api/v1/_test/throttled')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('traduz outros erros HTTP', function (string $path, int $status, string $message) {
    $this->getJson("/api/v1/_test/{$path}")
        ->assertStatus($status)
        ->assertJsonPath('error.code', 'http_error')
        ->assertJsonPath('error.message', $message);
})->with([
    'indisponível' => ['unavailable', 503, 'Serviço temporariamente indisponível.'],
    'outro' => ['teapot', 418, 'Não foi possível processar o pedido.'],
]);

it('não altera as respostas de páginas web', function () {
    $response = $this->get('/pagina-que-nao-existe')->assertNotFound();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});
