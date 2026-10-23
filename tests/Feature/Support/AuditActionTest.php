<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'audit:test.sensitive'])->post('/api/v1/_test/sensitive', fn () => response()->json(['ok' => true]));
});

it('regista ações sensíveis no audit log sem guardar o corpo do pedido', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/_test/sensitive', ['code' => '123456'])
        ->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('test.sensitive')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->request_id)->not->toBeNull()
        ->and($log->metadata)->toMatchArray(['status' => 200, 'success' => true])
        ->and(json_encode($log->metadata))->not->toContain('123456');
});

it('associa o registo ao utilizador e ao objeto afetado', function () {
    $user = User::factory()->create();

    $log = app(AuditLogger::class)->record('user.updated', $user, ['field' => 'name'], $user->id);

    expect($log->user?->is($user))->toBeTrue()
        ->and($log->subject?->is($user))->toBeTrue()
        ->and($log->metadata)->toBe(['field' => 'name']);
});
