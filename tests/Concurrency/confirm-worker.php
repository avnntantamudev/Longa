<?php

declare(strict_types=1);

/*
| Processo independente usado por QrConfirmConcurrencyTest: espera pela barreira e confirma a aula
| com o token indicado. Escreve o resultado em JSON.
|
| Uso: php tests/Concurrency/confirm-worker.php <sessionId> <token> <startAtMicrotime>
*/

use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Models\ServiceJob;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$sessionId, $token, $startAt] = [(int) $argv[1], (string) $argv[2], (float) $argv[3]];

$session = ServiceJob::query()->with('client')->findOrFail($sessionId);

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $confirmed = $app->make(ConfirmSession::class)->handle($session, $session->client, $token, null);
    echo json_encode(['ok' => true, 'state' => $confirmed->state->value]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e::class.': '.$e->getMessage()]);
}
