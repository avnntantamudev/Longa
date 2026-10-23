<?php

declare(strict_types=1);

/*
| Processo independente usado por DisputeResolveConcurrencyTest: espera pela barreira e decide a disputa
| como o admin indicado. Escreve o resultado em JSON.
|
| Uso: php tests/Concurrency/resolve-worker.php <disputeId> <adminId> <decision> <startAtMicrotime>
*/

use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$disputeId, $adminId, $decision, $startAt] = [(int) $argv[1], (int) $argv[2], (string) $argv[3], (float) $argv[4]];

$dispute = Dispute::query()->findOrFail($disputeId);
$admin = User::query()->findOrFail($adminId);

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $resolved = $app->make(ResolveDispute::class)->handle($dispute, $admin, $decision, null, 'Decisão concorrente');
    echo json_encode(['ok' => true, 'decision' => $decision, 'resolution' => $resolved->resolution]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'decision' => $decision, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'decision' => $decision, 'code' => 'exception', 'message' => $e::class.': '.$e->getMessage()]);
}
