<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Actions\CancelSession;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\ConfirmWithoutQr;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\OpenDispute;
use App\Modules\Sessions\Actions\ReportNoShow;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Modules\Wallet\Services\LedgerReconciler;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Teste por propriedades: centenas de fluxos aleatórios (valores, caminhos, repetições) e, depois de
| CADA fluxo, as invariantes do dinheiro têm de se manter. A semente é escrita no erro para reproduzir:
|   LONGA_PROPERTY_SEED=123 LONGA_PROPERTY_FLOWS=1000 php artisan test --filter=LedgerPropertyTest
*/

const SCENARIOS = [
    'qr', 'without_qr', 'student_cancel_early', 'student_cancel_late', 'trainer_cancel', 'no_show_trainer',
    'no_show_student', 'auto_not_started', 'auto_started', 'dispute', 'contested_no_show', 'payment_failed',
    'cash_confirmed', 'cash_auto',
];

/**
 * Executa um fluxo e devolve a parte bruta esperada do formador (null = sem liquidação no escrow).
 */
function runFlow(string $scenario, int $amount, ?int $trainerProfileId): array
{
    test()->travelBack();
    Cache::flush();
    $split = Commission::split($amount);
    $cash = str_starts_with($scenario, 'cash');
    $overrides = ['amount' => $amount, 'commission' => $split['commission'], 'trainer_net' => $split['trainer_net']];
    if ($trainerProfileId !== null) {
        $overrides['trainer_profile_id'] = $trainerProfileId;
    }
    $session = acceptedSession($cash ? 'cash' : 'electronic', $overrides);
    $trainer = $session->trainer->user;
    $student = $session->student;
    $admin = fn () => User::factory()->withRole(Role::Admin)->create();
    $percent = fn (int $p) => min($amount, intdiv($amount * $p + 50, 100));
    $refresh = fn (): TrainingSession => $session->fresh() ?? $session;
    $tick = fn () => test()->artisan('longa:sessions:tick');

    if ($scenario === 'payment_failed') {
        // O gateway é um singleton: recriá-lo para a configuração valer só neste fluxo
        config(['longa.payments.mock_outcome' => 'failed']);
        app()->forgetInstance(App\Modules\Payments\Contracts\PaymentProvider::class);
        try {
            app(PayForSession::class)->handle($session, $student, 'multicaixa_express');
        } catch (ApiException) {
        }
        config(['longa.payments.mock_outcome' => 'succeeded']);
        app()->forgetInstance(App\Modules\Payments\Contracts\PaymentProvider::class);

        return [$session, null];
    }

    if (! $cash) {
        app(PayForSession::class)->handle($session, $student, 'multicaixa_express');
        app(PayForSession::class)->handle($refresh(), $student, 'multicaixa_express'); // repetição: não cobra duas vezes
    }

    $runLesson = function () use ($session, $trainer, $refresh): string {
        test()->travelTo($session->scheduled_at);
        app(StartSession::class)->handle($refresh(), $trainer);

        return app(FinishSession::class)->handle($refresh(), $trainer)['qr']['tokens'][0]['token'];
    };
    $decide = function (TrainingSession $s) use ($admin, $amount): int {
        $dispute = $s->disputes()->sole();
        $choice = $amount < 2 ? mt_rand(0, 1) : mt_rand(0, 2);
        $share = [ResolveDispute::RELEASE => $amount, ResolveDispute::REFUND => 0, ResolveDispute::PARTIAL => mt_rand(1, max(1, $amount - 1))];
        $decision = array_keys($share)[$choice];
        app(ResolveDispute::class)->handle($dispute, $admin(), $decision, $share[$decision], 'Decisão aleatória');
        // Segunda decisão (outro admin, decisão contrária) não move dinheiro
        try {
            app(ResolveDispute::class)->handle($dispute, $admin(), $decision === ResolveDispute::REFUND ? ResolveDispute::RELEASE : ResolveDispute::REFUND, null, 'Concorrente');
        } catch (ApiException) {
        }

        return $share[$decision];
    };

    switch ($scenario) {
        case 'qr':
        case 'cash_confirmed':
        case 'cash_auto':
            $token = $runLesson();
            app(ConfirmSession::class)->handle($refresh(), $student, $token, null);
            app(ConfirmSession::class)->handle($refresh(), $student, $token, null); // dupla leitura
            if ($scenario === 'cash_confirmed') {
                app(ConfirmCashPayment::class)->handle($refresh(), $student);
            } elseif ($scenario === 'cash_auto') {
                test()->travel(49)->hours();
                $tick();
            }

            return [$session, $cash ? null : $amount];
        case 'without_qr':
            $runLesson();
            app(ConfirmWithoutQr::class)->handle($refresh(), $student);
            app(ConfirmWithoutQr::class)->handle($refresh(), $student);

            return [$session, $amount];
        case 'student_cancel_early':
            app(CancelSession::class)->handle($refresh(), $student, null);

            return [$session, 0];
        case 'student_cancel_late':
            test()->travelTo($session->scheduled_at->copy()->subHours(mt_rand(1, 5)));
            app(CancelSession::class)->handle($refresh(), $student, null);

            return [$session, $percent(25)];
        case 'trainer_cancel':
            app(CancelSession::class)->handle($refresh(), $trainer, null);

            return [$session, 0];
        case 'no_show_trainer':
        case 'no_show_student':
        case 'contested_no_show':
            $byStudent = $scenario !== 'no_show_student' && ($scenario === 'no_show_trainer' || mt_rand(0, 1) === 1);
            test()->travelTo($session->scheduled_at->copy()->addMinutes(35));
            app(ReportNoShow::class)->handle($refresh(), $byStudent ? $student : $trainer, null);
            if ($scenario === 'contested_no_show') {
                app(OpenDispute::class)->handle($refresh(), $byStudent ? $trainer : $student, 'no_show_contest', 'Estive presente na hora marcada.');
                test()->travel(25)->hours();
                $tick(); // não liquida enquanto houver disputa

                return [$session, $decide($refresh())];
            }
            test()->travel(25)->hours();
            $tick();
            $tick();

            return [$session, $byStudent ? 0 : $percent(50)];
        case 'auto_not_started':
            test()->travelTo($session->ends_at?->copy()->addHours(3));
            $tick();
            test()->travel(49)->hours();
            $tick();

            return [$session, 0];
        case 'auto_started':
            $runLesson();
            test()->travelTo($session->ends_at?->copy()->addHours(3));
            $tick();
            test()->travel(49)->hours();
            $tick();

            return [$session, $amount];
        case 'dispute':
            $runLesson();
            app(OpenDispute::class)->handle($refresh(), $student, 'quality', 'A aula ficou aquém do combinado.');

            return [$session, $decide($refresh())];
    }

    throw new LogicException($scenario);
}

function assertMoneyInvariants(string $context): void
{
    expect((int) LedgerEntry::query()->sum('amount'))->toBe(0, "{$context}: soma global ≠ 0");

    $unbalanced = DB::table('ledger_entries')->select('ledger_transaction_id')->groupBy('ledger_transaction_id')->havingRaw('SUM(amount) <> 0')->count();
    expect($unbalanced)->toBe(0, "{$context}: transação desequilibrada");

    $cacheMismatch = DB::table('ledger_account_balances as b')
        ->leftJoinSub(DB::table('ledger_entries')->selectRaw('ledger_account_id, SUM(amount) t')->groupBy('ledger_account_id'), 'e', 'e.ledger_account_id', '=', 'b.ledger_account_id')
        ->whereRaw('b.balance <> COALESCE(e.t, 0)')->count();
    expect($cacheMismatch)->toBe(0, "{$context}: cache de saldos ≠ lançamentos");

    $escrow = (int) DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')->where('a.code', Accounts::ESCROW)->sum('e.amount');
    expect($escrow)->toBe((int) EscrowHold::query()->whereIn('state', EscrowHold::ACTIVE)->sum('amount'), "{$context}: escrow ≠ retenções ativas");

    $negative = DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
        ->where('a.type', 'trainer_available')->select('a.id')->groupBy('a.id')->havingRaw('SUM(e.amount) < 0')->count();
    expect($negative)->toBe(0, "{$context}: saldo de formador negativo");

    // Reembolsos executados (fila síncrona nos testes): nada fica por pagar
    $refunds = (int) DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')->where('a.code', Accounts::REFUNDS_PAYABLE)->sum('e.amount');
    expect($refunds)->toBe(0, "{$context}: reembolsos por executar");
}

it('centenas de fluxos aleatórios mantêm as invariantes do dinheiro', function () {
    Notification::fake();
    $seed = (int) (env('LONGA_PROPERTY_SEED') ?: random_int(1, PHP_INT_MAX >> 1));
    $flows = (int) (env('LONGA_PROPERTY_FLOWS') ?: 200);
    mt_srand($seed);
    $edgeAmounts = [1, 2, 3, 7, 999, 25000, 450000];
    $lastTrainer = null;
    $seen = [];

    for ($i = 0; $i < $flows; $i++) {
        $scenario = SCENARIOS[mt_rand(0, count(SCENARIOS) - 1)];
        $amount = mt_rand(0, 4) === 0 ? $edgeAmounts[mt_rand(0, count($edgeAmounts) - 1)] : mt_rand(1, 500000);
        // Às vezes o mesmo formador: comissões em mão compensadas em aulas seguintes
        $reuse = $lastTrainer !== null && mt_rand(0, 3) === 0 ? $lastTrainer : null;
        $context = "semente {$seed}, fluxo {$i} ({$scenario}, {$amount} Kz)";

        [$session, $gross] = runFlow($scenario, $amount, $reuse);
        $lastTrainer = $session->trainer_profile_id;
        $seen[$scenario] = true;

        $settlements = LedgerTransaction::query()->where('idempotency_key', "escrow:settle:session:{$session->id}")->with('entries.account')->get();
        if ($gross === null) {
            expect($settlements)->toHaveCount(0, $context);
        } else {
            expect($settlements)->toHaveCount(1, "{$context}: liquidação tem de existir uma única vez");
            $lines = $settlements->first()->entries->mapWithKeys(fn (LedgerEntry $e) => [explode(':', $e->account->code)[0] => $e->amount]);
            $expected = Commission::split($gross);
            expect((int) ($lines['escrow'] ?? 0))->toBe(-$amount, $context)
                ->and((int) ($lines['trainer_available'] ?? 0))->toBe($expected['trainer_net'], $context)
                ->and((int) ($lines['platform_revenue'] ?? 0))->toBe($expected['commission'], $context)
                ->and((int) ($lines['refunds_payable'] ?? 0))->toBe($amount - $gross, $context);
        }

        assertMoneyInvariants($context);
    }

    test()->travelBack();
    expect(app(LedgerReconciler::class)->run()->status)->toBe('ok', "semente {$seed}: reconciliação final")
        ->and(count($seen))->toBeGreaterThan(min(10, $flows / 10), "semente {$seed}: poucos cenários cobertos");
});
