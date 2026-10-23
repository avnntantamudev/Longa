<?php

declare(strict_types=1);

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Qr\Models\SessionQrToken;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

/**
 * Leva a aula até ao QR: hora da aula, formador inicia e termina. Devolve os tokens do lote.
 *
 * @return list<array{token: string, code: string, valid_from: string, expires_at: string}>
 */
function showQr(TrainingSession $session): array
{
    test()->travelTo($session->scheduled_at->copy()->addMinutes(5));
    apiLogin($session->trainer->user);
    test()->postJson("/api/v1/sessions/{$session->id}/start")->assertOk()->assertJsonPath('data.state', 'in_progress');

    test()->travelTo($session->ends_at?->copy()->subMinutes(2));

    return test()->postJson("/api/v1/sessions/{$session->id}/finish")
        ->assertOk()
        ->assertJsonPath('data.state', 'awaiting_confirmation')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('qr.tokens');
}

function confirmWith(TrainingSession $session, array $body, string $key = 'confirm-0001'): Illuminate\Testing\TestResponse
{
    apiLogin($session->student);

    return test()->postJson("/api/v1/sessions/{$session->id}/confirm", $body, ['Idempotency-Key' => $key]);
}

it('o aluno lê o QR: aula concluída e valor libertado para o formador menos a comissão', function () {
    $session = paidSession();
    $tokens = showQr($session);

    expect($tokens)->toHaveCount(10)
        ->and(strlen($tokens[0]['token']))->toBe(22)
        ->and(SessionQrToken::query()->whereIn('token_hash', array_column($tokens, 'token'))->count())->toBe(0); // só hashes

    confirmWith($session, ['token' => $tokens[0]['token']])
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('message', 'Aula confirmada, Kz 25.000 libertados para o formador.');

    $ledger = app(LedgerService::class);
    expect($ledger->balance(Accounts::ESCROW))->toBe(0)
        ->and($ledger->balance(Accounts::trainerAvailable($session->trainer_profile_id)))->toBe(21250)
        ->and($ledger->balance(Accounts::PLATFORM_REVENUE))->toBe(3750)
        ->and(EscrowHold::query()->sole()->state)->toBe('released')
        ->and(SessionEvent::query()->where('training_session_id', $session->id)->pluck('type')->all())
        ->toContain('started', 'finished', 'qr_issued', 'qr_scanned', 'confirmed');
});

it('confirmar de novo devolve o mesmo resultado sem libertar o dinheiro outra vez', function () {
    $session = paidSession();
    $tokens = showQr($session);

    confirmWith($session, ['token' => $tokens[0]['token']], 'confirm-a-0001')->assertOk();
    confirmWith($session, ['token' => $tokens[0]['token']], 'confirm-b-0002')->assertOk()->assertJsonPath('data.state', 'completed');
    confirmWith($session, ['code' => $tokens[1]['code']], 'confirm-c-0003')->assertOk();

    expect(LedgerTransaction::query()->where('type', 'escrow_release')->count())->toBe(1)
        ->and(app(LedgerService::class)->trainerAvailable($session->trainer))->toBe(21250);
});

it('recusa um QR expirado', function () {
    $session = paidSession();
    $tokens = showQr($session);

    $this->travel(46)->seconds(); // janela do 1.º token: 30 s + 15 s de margem
    confirmWith($session, ['token' => $tokens[0]['token']])->assertStatus(422)->assertJsonPath('error.code', 'qr_invalid');

    expect($session->fresh()?->state)->toBe(SessionState::AwaitingConfirmation);
});

it('o QR roda: o token seguinte só vale na sua janela', function () {
    $session = paidSession();
    $tokens = showQr($session);

    confirmWith($session, ['token' => $tokens[1]['token']], 'confirm-early1')->assertStatus(422);

    $this->travel(31)->seconds();
    confirmWith($session, ['token' => $tokens[1]['token']], 'confirm-ontime')->assertOk()->assertJsonPath('data.state', 'completed');
});

it('aceita o código de 6 dígitos como alternativa ao QR', function () {
    $session = paidSession();
    $tokens = showQr($session);

    confirmWith($session, ['code' => $tokens[0]['code']])->assertOk()->assertJsonPath('data.state', 'completed');
    expect(SessionEvent::query()->where('type', 'confirmed')->sole()->metadata['method'])->toBe('code');
});

it('recusa um QR de outra aula e um QR já usado noutra aula', function () {
    $a = paidSession();
    $b = paidSession();
    $tokensA = showQr($a);
    $tokensB = showQr($b);

    confirmWith($a, ['token' => $tokensB[0]['token']])->assertStatus(422);
    confirmWith($b, ['token' => $tokensB[0]['token']], 'confirm-b-ok01')->assertOk();
    confirmWith($a, ['token' => $tokensB[0]['token']], 'confirm-a-reuse')->assertStatus(422);
    expect($tokensA)->toHaveCount(10);
});

it('só o aluno da aula confirma: o formador não se autoconfirma', function () {
    $session = paidSession();
    $tokens = showQr($session);

    apiLogin($session->trainer->user);
    $this->postJson("/api/v1/sessions/{$session->id}/confirm", ['token' => $tokens[0]['token']], ['Idempotency-Key' => 'self-confirm'])->assertForbidden();

    apiLogin(User::factory()->create());
    $this->postJson("/api/v1/sessions/{$session->id}/confirm", ['token' => $tokens[0]['token']], ['Idempotency-Key' => 'other-confirm'])->assertForbidden();

    expect($session->fresh()?->state)->toBe(SessionState::AwaitingConfirmation);
});

it('recusa a confirmação fora da janela de tempo', function () {
    $session = paidSession();
    // Formador começa 10 min antes e termina logo: o QR existe mas ainda não é a hora da aula
    $this->travelTo($session->scheduled_at->copy()->subMinutes(10));
    apiLogin($session->trainer->user);
    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertOk();
    $tokens = $this->postJson("/api/v1/sessions/{$session->id}/finish")->json('qr.tokens');

    confirmWith($session, ['token' => $tokens[0]['token']])->assertStatus(409)->assertJsonPath('error.code', 'qr_outside_window');
});

it('bloqueia após tentativas erradas, mesmo com o código certo', function () {
    $session = paidSession();
    $tokens = showQr($session);

    foreach (range(1, 5) as $i) {
        confirmWith($session, ['code' => '000000'], "wrong-code-{$i}x")->assertStatus(422);
    }

    confirmWith($session, ['code' => $tokens[0]['code']], 'right-code-late')->assertStatus(429)->assertJsonPath('error.code', 'qr_locked');
    expect(SessionQrToken::query()->where('training_session_id', $session->id)->sum('attempts'))->toBeGreaterThanOrEqual(5);
});

it('regista sinal de risco se o aluno estiver longe do local, sem bloquear', function () {
    $session = paidSession();
    $tokens = showQr($session);

    confirmWith($session, ['token' => $tokens[0]['token'], 'latitude' => -8.8160, 'longitude' => 13.2340]) // Ingombota, ~13 km
        ->assertOk();

    $risk = SessionEvent::query()->where('type', 'risk_flagged')->sole();
    expect($risk->metadata['distance_m'])->toBeGreaterThan(10000);
});

it('o formador só inicia na hora e não termina sem iniciar', function () {
    $session = paidSession();
    apiLogin($session->trainer->user);

    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertStatus(409)->assertJsonPath('error.code', 'session_too_early');
    $this->postJson("/api/v1/sessions/{$session->id}/finish")->assertStatus(409);

    apiLogin($session->student);
    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertForbidden();
});

it('não se inicia uma aula por pagar', function () {
    $session = acceptedSession();
    $this->travelTo($session->scheduled_at);
    apiLogin($session->trainer->user);

    $this->postJson("/api/v1/sessions/{$session->id}/start")->assertStatus(409)->assertJsonPath('error.code', 'session_not_startable');
});
