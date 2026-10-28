<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Concorrência real: 6 administradores decidem a mesma disputa ao mesmo tempo (3 libertam, 3 reembolsam).
| Só uma decisão move dinheiro; as do mesmo sentido são idempotentes e as contrárias recebem 409.
*/

it('decisões simultâneas de admins diferentes movem o dinheiro uma única vez', function () {
    Illuminate\Support\Facades\Queue::fake();
    $session = paidSession(['scheduled_at' => now()->subMinutes(5)]);
    app(StartSession::class)->handle($session, $session->provider->user);
    app(FinishSession::class)->handle($session, $session->provider->user);
    $dispute = app(OpenDispute::class)->handle($session->fresh() ?? $session, $session->client, 'quality', 'A aula ficou muito aquém do combinado.');

    $php = (new PhpExecutableFinder)->find() ?: 'php';
    $startAt = microtime(true) + 4;
    $decisions = ['released', 'refunded', 'released', 'refunded', 'released', 'refunded'];
    $processes = array_map(function (string $decision) use ($php, $dispute, $startAt) {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $p = new Process([$php, base_path('tests/Concurrency/resolve-worker.php'), (string) $dispute->id, (string) $admin->id, $decision, sprintf('%.6F', $startAt)], base_path());
        $p->setTimeout(60);
        $p->start();

        return $p;
    }, $decisions);

    $results = array_map(function (Process $p) {
        $p->wait();

        return json_decode(trim($p->getOutput()), true) ?? ['ok' => false, 'code' => 'no_output', 'message' => $p->getErrorOutput()];
    }, $processes);

    $winner = $dispute->fresh()?->resolution;
    foreach ($results as $result) {
        if ($result['decision'] === $winner) {
            expect($result['ok'])->toBeTrue(json_encode($results));
        } else {
            expect($result)->toMatchArray(['ok' => false, 'code' => 'dispute_already_resolved']);
        }
    }

    $ledger = app(LedgerService::class);
    expect(LedgerTransaction::query()->where('idempotency_key', "escrow:settle:session:{$session->id}")->count())->toBe(1)
        ->and($ledger->balanceFromEntries(Accounts::ESCROW))->toBe(0)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and(EscrowHold::query()->sole()->state)->toBe($winner)
        ->and($ledger->balanceFromEntries(Accounts::providerAvailable($session->provider_profile_id)))->toBe($winner === 'released' ? 21250 : 0);
});
