<?php

declare(strict_types=1);

use App\Modules\Payments\Models\ProviderSubscription;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerEntry;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Concorrência real: 6 processos compram o VIP do mesmo técnico ao mesmo tempo — 3 com a mesma chave
| (duplo toque) e 3 com chaves diferentes (abas diferentes). A mesma chave cobra uma vez; chaves diferentes
| em simultâneo ficam bloqueadas (payment_in_progress) enquanto há um pagamento em curso.
*/

it('compras VIP simultâneas não cobram nem ativam a mais', function () {
    $provider = ProviderProfile::factory()->verified()->create();

    $php = (new PhpExecutableFinder)->find() ?: 'php';
    $startAt = microtime(true) + 4;
    $keys = ['same', 'same', 'same', 'tab-a', 'tab-b', 'tab-c'];
    $processes = array_map(function (string $key) use ($php, $provider, $startAt) {
        $p = new Process([$php, base_path('tests/Concurrency/vip-worker.php'), (string) $provider->id, $key, sprintf('%.6F', $startAt)], base_path());
        $p->setTimeout(60);
        $p->start();

        return $p;
    }, $keys);

    $results = array_map(function (Process $p) {
        $p->wait();

        return json_decode(trim($p->getOutput()), true) ?? ['ok' => false, 'code' => 'no_output', 'message' => $p->getErrorOutput()];
    }, $processes);

    foreach ($results as $result) {
        expect($result['ok'] || $result['code'] === 'payment_in_progress')->toBeTrue(json_encode($results));
    }

    $active = ProviderSubscription::query()->where('status', 'active')->count();
    $ledger = app(LedgerService::class);
    // Cada subscrição ativa corresponde a exatamente um lançamento de 50.000 Kz
    expect($ledger->balanceFromEntries(Accounts::SUBSCRIPTION_REVENUE))->toBe($active * 50000)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and($active)->toBeGreaterThanOrEqual(1)
        ->and(ProviderSubscription::query()->where('idempotency_key', "vip:{$provider->id}:same")->count())->toBeLessThanOrEqual(1);

    // Períodos encadeados sem sobreposição
    $periods = ProviderSubscription::query()->where('status', 'active')->orderBy('starts_at')->get(['starts_at', 'ends_at']);
    for ($i = 1; $i < $periods->count(); $i++) {
        expect($periods[$i]->starts_at?->equalTo($periods[$i - 1]->ends_at))->toBeTrue();
    }
});
