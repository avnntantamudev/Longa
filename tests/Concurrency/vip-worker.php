<?php

declare(strict_types=1);

/*
| Processo independente usado por VipPurchaseConcurrencyTest: espera pela barreira e compra o VIP
| com a chave indicada. Escreve o resultado em JSON.
|
| Uso: php tests/Concurrency/vip-worker.php <providerProfileId> <idempotencyKey> <startAtMicrotime>
*/

use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\ProviderProfile;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$providerId, $key, $startAt] = [(int) $argv[1], (string) $argv[2], (float) $argv[3]];

$provider = ProviderProfile::query()->with('user')->findOrFail($providerId);

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $subscription = $app->make(VipSubscriptions::class)->purchase($provider, $provider->user, 'multicaixa_express', $key);
    echo json_encode(['ok' => true, 'status' => $subscription->status, 'id' => $subscription->id]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e::class.': '.$e->getMessage()]);
}
