<?php

declare(strict_types=1);

use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Concorrência real: 8 processos tentam PIN errados ao mesmo tempo no mesmo aparelho.
| A contagem é feita com a linha bloqueada: nenhuma tentativa escapa e o bloqueio começa à 5.ª.
*/

it('tentativas simultâneas de PIN não escapam ao limite', function () {
    $user = User::factory()->create(['pin_hash' => Hash::make('482916')]);
    $device = TrustedDevice::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', str_repeat('x', 64)), 'name' => 'Teste']);

    $php = (new PhpExecutableFinder)->find() ?: 'php';
    $startAt = microtime(true) + 4;
    $processes = array_map(function () use ($php, $device, $startAt) {
        $p = new Process([$php, base_path('tests/Concurrency/pin-worker.php'), $device->id, '000000', sprintf('%.6F', $startAt)], base_path());
        $p->setTimeout(60);
        $p->start();

        return $p;
    }, range(1, 8));

    $codes = array_map(function (Process $p) {
        $p->wait();

        return (json_decode(trim($p->getOutput()), true) ?? ['code' => 'no_output: '.$p->getErrorOutput()])['code'] ?? 'ok';
    }, $processes);

    $fresh = $device->fresh();
    // Exatamente 5 contadas (a 5.ª bloqueia); as restantes chegam já bloqueadas
    expect($fresh?->failed_attempts)->toBe(5, json_encode($codes))
        ->and($fresh?->locked_until?->isFuture())->toBeTrue()
        ->and(array_count_values($codes))->toBe(['pin_invalid' => 5, 'pin_locked' => 3]);
});
