<?php

declare(strict_types=1);

/*
| Processo independente usado por AcceptOfferConcurrencyTest: arranca a aplicação, espera pela
| hora combinada (barreira) e tenta aceitar a oferta. Escreve o resultado em JSON no stdout.
|
| Uso: php tests/Concurrency/accept-worker.php <offerId> <startAtMicrotime>
*/

use App\Modules\Requests\Actions\AcceptOffer;
use App\Modules\Requests\Models\RequestOffer;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$offerId, $startAt] = [(int) $argv[1], (float) $argv[2]];

$offer = RequestOffer::query()->findOrFail($offerId);

// Barreira: todos os processos avançam no mesmo instante
while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $session = $app->make(AcceptOffer::class)->handle($offer);
    echo json_encode(['offer' => $offerId, 'ok' => true, 'session' => $session->id]);
} catch (ApiException $e) {
    echo json_encode(['offer' => $offerId, 'ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['offer' => $offerId, 'ok' => false, 'code' => 'exception', 'message' => $e::class.': '.$e->getMessage()]);
}
