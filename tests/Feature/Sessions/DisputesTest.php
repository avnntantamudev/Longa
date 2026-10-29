<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Sessions\Events\DisputeOpened;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Cache::flush();
    Storage::fake('local');
});

/** Serviço pago, iniciado e à espera de confirmação do cliente. */
function awaitingSession(): ServiceRequest
{
    $session = paidSession();
    test()->travelTo($session->scheduled_at);
    app(StartSession::class)->handle($session, $session->provider->user);
    test()->travelTo($session->ends_at?->copy()->addHours(3));
    test()->artisan('longa:sessions:tick');

    return $session->fresh() ?? $session;
}

/**
 * @param  array<string, mixed>  $body
 */
function openDispute(ServiceRequest $session, User $as, array $body = []): Illuminate\Testing\TestResponse
{
    apiLogin($as);

    return test()->post("/api/v1/sessions/{$session->id}/disputes", [
        'reason' => 'lesson_not_happened',
        'description' => 'O técnico chegou e saiu passados 10 minutos.',
        ...$body,
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'dispute-'.uniqid()]);
}

function resolveAs(User $admin, Dispute $dispute, array $body): Illuminate\Testing\TestResponse
{
    apiLogin($admin);

    return test()->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [...$body, 'note' => 'Analisadas as evidências.'], ['Idempotency-Key' => 'resolve-'.uniqid()]);
}

it('o cliente abre disputa com anexos: serviço em disputa e dinheiro retido', function () {
    Event::fake([DisputeOpened::class]);
    $session = awaitingSession();

    openDispute($session, $session->client, ['attachments' => [
        UploadedFile::fake()->image('foto.jpg'),
        UploadedFile::fake()->create('recibo.pdf', 100, 'application/pdf'),
    ]])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonCount(2, 'data.attachments');

    $dispute = Dispute::query()->sole();
    expect($session->fresh()?->state)->toBe(RequestState::Disputed)
        ->and(EscrowHold::query()->sole()->state)->toBe(EscrowHold::DISPUTED)
        ->and(balances($session)['escrow'])->toBe(25000);
    foreach ($dispute->attachments as $attachment) {
        Storage::disk('local')->assertExists($attachment->path);
    }
    Event::assertDispatched(DisputeOpened::class);

    // Uma disputa aberta não fecha pelas regras automáticas
    $this->travel(5)->days();
    $this->artisan('longa:sessions:tick');
    expect($session->fresh()?->state)->toBe(RequestState::Disputed);
});

it('valida tipo, tamanho e número de anexos', function () {
    $session = awaitingSession();

    $details = openDispute($session, $session->client, ['attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])
        ->assertStatus(422)->json('error.details');
    expect($details['attachments.0'][0])->toBe('Só são aceites imagens (JPG, PNG, WebP) ou PDF.');
    openDispute($session, $session->client, ['attachments' => [UploadedFile::fake()->image('grande.jpg')->size(6000)]])
        ->assertStatus(422);
    openDispute($session, $session->client, ['attachments' => array_fill(0, 6, UploadedFile::fake()->image('a.jpg'))])
        ->assertStatus(422);
    openDispute($session, $session->client, ['description' => 'curto'])->assertStatus(422);

    expect(Dispute::query()->count())->toBe(0);
});

it('o cliente ou o técnico abrem disputa durante a confirmação; terceiros não veem o serviço', function () {
    $session = awaitingSession();

    openDispute($session, User::factory()->withRole(Role::Client)->create())->assertForbidden();
    openDispute($session, $session->provider->user)->assertCreated();
    expect($session->fresh()?->state)->toBe(RequestState::Disputed);
});

it('não se abre disputa num serviço agendado ou já concluída', function () {
    $session = paidSession();
    openDispute($session, $session->client)->assertStatus(409);
});

it('o técnico contesta a falta dentro de 24 h; depois já não', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->addMinutes(20));
    apiLogin($session->client);
    $this->postJson("/api/v1/sessions/{$session->id}/no-show", [], ['Idempotency-Key' => 'ns-'.uniqid()])->assertOk();

    openDispute($session, $session->client, ['reason' => 'no_show_contest'])->assertStatus(409);
    openDispute($session, $session->provider->user, ['reason' => 'no_show_contest'])->assertCreated();
    // O pedido continua cancelado (falta); a disputa retém o dinheiro até à decisão da equipa
    expect($session->fresh()?->state)->toBe(RequestState::Cancelled);

    // Sem liquidação automática enquanto a disputa está aberta
    $this->travel(25)->hours();
    $this->artisan('longa:sessions:tick');
    expect(balances($session)['escrow'])->toBe(25000);
});

it('contestação fora do prazo é recusada', function () {
    $session = paidSession();
    $this->travelTo($session->scheduled_at->copy()->addMinutes(20));
    apiLogin($session->client);
    $this->postJson("/api/v1/sessions/{$session->id}/no-show", [], ['Idempotency-Key' => 'ns-'.uniqid()])->assertOk();

    $this->travel(25)->hours();
    openDispute($session, $session->provider->user, ['reason' => 'no_show_contest'])->assertStatus(409);
});

it('o admin liberta ao técnico', function () {
    $session = awaitingSession();
    openDispute($session, $session->client)->assertCreated();
    $admin = User::factory()->withRole(Role::Admin)->create();

    resolveAs($admin, Dispute::query()->sole(), ['decision' => 'released'])->assertOk()->assertJsonPath('data.resolution', 'released');

    expect($session->fresh()?->state)->toBe(RequestState::Paid)
        ->and(balances($session))->toMatchArray(['escrow' => 0, 'provider' => 21250, 'revenue' => 3750])
        ->and(AuditLog::query()->where('action', 'disputes.resolved')->count())->toBe(1);
});

it('o admin reembolsa tudo ao cliente', function () {
    $session = awaitingSession();
    openDispute($session, $session->client)->assertCreated();

    resolveAs(User::factory()->withRole(Role::Admin)->create(), Dispute::query()->sole(), ['decision' => 'refunded'])->assertOk();

    expect($session->fresh()?->state)->toBe(RequestState::Cancelled)
        ->and(balances($session))->toBe(['escrow' => 0, 'provider' => 0, 'revenue' => 0, 'refunds' => 0, 'funds_in' => 0]);
});

it('o admin reembolsa em parte: comissão só sobre a parte do técnico', function () {
    $session = awaitingSession();
    openDispute($session, $session->client)->assertCreated();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $dispute = Dispute::query()->sole();

    resolveAs($admin, $dispute, ['decision' => 'partially_refunded'])->assertStatus(422);
    resolveAs($admin, $dispute, ['decision' => 'partially_refunded', 'provider_share' => 25000])->assertStatus(422);
    resolveAs($admin, $dispute, ['decision' => 'partially_refunded', 'provider_share' => 10000])->assertOk();

    // 10.000 → comissão 1.500, técnico 8.500; reembolso 15.000
    expect($session->fresh()?->state)->toBe(RequestState::Paid)
        ->and(balances($session))->toBe(['escrow' => 0, 'provider' => 8500, 'revenue' => 1500, 'refunds' => 0, 'funds_in' => -10000]);
});

it('uma segunda decisão diferente é recusada e não move dinheiro', function () {
    $session = awaitingSession();
    openDispute($session, $session->client)->assertCreated();
    $dispute = Dispute::query()->sole();

    resolveAs(User::factory()->withRole(Role::Admin)->create(), $dispute, ['decision' => 'released'])->assertOk();
    resolveAs(User::factory()->withRole(Role::Admin)->create(), $dispute, ['decision' => 'refunded'])
        ->assertStatus(409)->assertJsonPath('error.code', 'dispute_already_resolved');

    // A mesma decisão repetida é idempotente
    app(ResolveDispute::class)->handle($dispute, User::factory()->withRole(Role::Admin)->create(), 'released', null, 'Repetido');

    expect(balances($session))->toMatchArray(['provider' => 21250, 'revenue' => 3750, 'escrow' => 0]);
});

it('só o admin decide', function () {
    $session = awaitingSession();
    openDispute($session, $session->client)->assertCreated();

    resolveAs($session->client, Dispute::query()->sole(), ['decision' => 'refunded'])->assertForbidden();
});

it('evidências por link assinado temporário, só para participantes e admin', function () {
    $session = awaitingSession();
    openDispute($session, $session->client, ['attachments' => [UploadedFile::fake()->image('foto.png')]])->assertCreated();
    $attachmentId = Dispute::query()->sole()->attachments()->sole()->id;

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson("/api/v1/dispute-attachments/{$attachmentId}")->assertForbidden();

    apiLogin($session->provider->user);
    $url = $this->getJson("/api/v1/dispute-attachments/{$attachmentId}")->assertOk()->json('data.url');

    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get("/disputas/anexos/{$attachmentId}")->assertForbidden();

    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});
