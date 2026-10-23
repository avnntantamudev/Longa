<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

it('reporta a aplicação, a base de dados e o Redis como operacionais', function () {
    Redis::shouldReceive('connection->ping')->once()->andReturn(true);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.app.status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonStructure(['version', 'time']);
});

it('responde 503 quando o Redis está em baixo', function () {
    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException('Connection refused'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis.status', 'fail')
        ->assertJsonMissingPath('checks.redis.message');
});
