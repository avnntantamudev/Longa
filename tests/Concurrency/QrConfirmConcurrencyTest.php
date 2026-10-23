<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Concorrência real: 6 processos leem o MESMO QR ao mesmo tempo. O dinheiro tem de ser libertado
| uma única vez e todos recebem a aula concluída (idempotência).
*/

it('duas ou mais leituras simultâneas do mesmo QR libertam o dinheiro uma única vez', function () {
    // Aula a decorrer agora (tempo real: os processos filhos não partilham o relógio dos testes)
    $session = paidSession(['scheduled_at' => now()->subMinutes(5)]);
    app(StartSession::class)->handle($session, $session->trainer->user);
    $token = app(FinishSession::class)->handle($session, $session->trainer->user)['qr']['tokens'][0]['token'];

    $php = (new PhpExecutableFinder)->find() ?: 'php';
    $startAt = microtime(true) + 4;
    $processes = array_map(function () use ($php, $session, $token, $startAt) {
        $p = new Process([$php, base_path('tests/Concurrency/confirm-worker.php'), (string) $session->id, $token, sprintf('%.6F', $startAt)], base_path());
        $p->setTimeout(60);
        $p->start();

        return $p;
    }, range(1, 6));

    $results = array_map(function (Process $p) {
        $p->wait();

        return json_decode(trim($p->getOutput()), true) ?? ['ok' => false, 'code' => 'no_output', 'message' => $p->getErrorOutput()];
    }, $processes);

    expect(array_column($results, 'ok'))->toBe(array_fill(0, 6, true), json_encode($results))
        ->and(array_unique(array_column($results, 'state')))->toBe(['completed']);

    expect(LedgerTransaction::query()->where('type', 'escrow_release')->count())->toBe(1)
        ->and(app(LedgerService::class)->balanceFromEntries(Accounts::trainerAvailable($session->trainer_profile_id)))->toBe(21250)
        ->and(app(LedgerService::class)->balanceFromEntries(Accounts::ESCROW))->toBe(0)
        ->and(EscrowHold::query()->sole()->state)->toBe('released')
        ->and($session->fresh()?->state)->toBe(SessionState::Completed);
});
