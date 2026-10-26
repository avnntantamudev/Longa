<?php

declare(strict_types=1);

/*
| Processo independente usado por PinAttemptsConcurrencyTest: espera pela barreira e tenta um PIN.
|
| Uso: php tests/Concurrency/pin-worker.php <deviceId> <pin> <startAtMicrotime>
*/

use App\Modules\Auth\Models\TrustedDevice;
use App\Modules\Auth\Services\PinService;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$deviceId, $pin, $startAt] = [(string) $argv[1], (string) $argv[2], (float) $argv[3]];

$device = TrustedDevice::query()->findOrFail($deviceId);

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $app->make(PinService::class)->attempt($device, $pin);
    echo json_encode(['ok' => true]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e::class.': '.$e->getMessage()]);
}
