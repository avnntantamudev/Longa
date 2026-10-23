<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->calls = 0;
    Route::middleware(['api', 'auth:sanctum', 'auth.session', 'idempotent'])->group(function () {
        Route::post('/api/v1/_test/pay', function () {
            $this->calls++;

            return response()->json(['charge' => $this->calls, 'amount' => request('amount')], 201);
        });
        Route::post('/api/v1/_test/fail', fn () => throw new ApiException('payment_declined', 'Pagamento recusado.', 402));
    });
    apiLogin(User::factory()->create());
});

it('exige a Idempotency-Key', function () {
    $this->postJson('/api/v1/_test/pay', ['amount' => 25000])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    expect($this->calls)->toBe(0);
});

it('repetir com a mesma chave devolve a resposta original sem executar de novo', function () {
    $headers = ['Idempotency-Key' => 'pay-abc-12345'];

    $first = $this->postJson('/api/v1/_test/pay', ['amount' => 25000], $headers)->assertCreated()->json();
    $this->postJson('/api/v1/_test/pay', ['amount' => 25000], $headers)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first);

    expect($this->calls)->toBe(1);
});

it('recusa a mesma chave com outro pedido', function () {
    $headers = ['Idempotency-Key' => 'pay-abc-12345'];
    $this->postJson('/api/v1/_test/pay', ['amount' => 25000], $headers)->assertCreated();

    $this->postJson('/api/v1/_test/pay', ['amount' => 99999], $headers)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('guarda também as respostas de erro de domínio', function () {
    $headers = ['Idempotency-Key' => 'fail-abc-12345'];

    $this->postJson('/api/v1/_test/fail', [], $headers)->assertStatus(402);
    $this->postJson('/api/v1/_test/fail', [], $headers)->assertStatus(402)->assertHeader('Idempotent-Replayed', 'true');
});

it('as chaves são por utilizador', function () {
    $headers = ['Idempotency-Key' => 'pay-abc-12345'];
    $this->postJson('/api/v1/_test/pay', ['amount' => 1], $headers)->assertCreated();

    apiLogin(User::factory()->create());
    $this->postJson('/api/v1/_test/pay', ['amount' => 2], $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect($this->calls)->toBe(2);
});
