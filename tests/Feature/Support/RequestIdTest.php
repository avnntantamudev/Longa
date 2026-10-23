<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::shouldReceive('connection->ping')->andReturn(true));

it('gera um X-Request-Id para cada pedido', function () {
    $id = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');

    expect($id)->toBeString()->toMatch('/^[0-9a-f-]{36}$/');
});

it('reaproveita um X-Request-Id válido recebido do cliente', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'flutter-abc-12345'])
        ->assertHeader('X-Request-Id', 'flutter-abc-12345');
});

it('ignora um X-Request-Id malformado', function () {
    $id = $this->getJson('/api/v1/health', ['X-Request-Id' => "x\r\nSet-Cookie: a"])->headers->get('X-Request-Id');

    expect($id)->not->toContain('Set-Cookie');
});
