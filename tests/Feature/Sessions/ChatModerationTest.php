<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\FlaggedMessages\Pages\ListFlaggedMessages;
use App\Modules\Sessions\Events\MessageSent;
use App\Modules\Sessions\Events\MessagesRead;
use App\Modules\Sessions\Models\Message;
use App\Modules\Sessions\Services\ChatFlagger;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| BISCA, Fase 3.4: chat ligado ao pedido com fotos e estado de leitura; contactos nunca são mostrados; mensagens com
| contactos ou tentativas de combinar fora da plataforma ficam sinalizadas para a equipa.
*/

beforeEach(function () {
    Storage::fake('local');
    Event::fake([MessageSent::class, MessagesRead::class]);
    $this->session = acceptedSession('cash');
});

function chat(string $as, array $body = []): Illuminate\Testing\TestResponse
{
    $session = test()->session;
    apiLogin($as === 'client' ? $session->client : $session->provider->user);

    return test()->post("/api/v1/sessions/{$session->id}/messages", $body, ['Accept' => 'application/json']);
}

it('envia fotos no chat (e recusa ficheiros que não são fotos nem PDF)', function () {
    $id = chat('client', ['attachment' => UploadedFile::fake()->image('avaria.jpg')])
        ->assertCreated()->assertJsonPath('data.attachment.kind', 'photo')->json('data.id');

    $url = $this->getJson("/api/v1/messages/{$id}/attachment")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    chat('client', ['attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])->assertUnprocessable();
    chat('client', ['attachment' => UploadedFile::fake()->image('grande.jpg')->size(6000)])->assertUnprocessable();
});

it('estado de leitura: a outra parte marca como lidas e quem escreveu é avisado', function () {
    chat('client', ['body' => 'Bom dia, a que horas chega?'])->assertCreated()->assertJsonPath('data.read_at', null);
    chat('client', ['body' => 'Obrigado'])->assertCreated();

    apiLogin($this->session->provider->user);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages/read")->assertOk()->assertJsonPath('data.marked', 2);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages/read")->assertOk()->assertJsonPath('data.marked', 0);

    apiLogin($this->session->client);
    $messages = $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->json('data');
    expect(array_filter(array_column($messages, 'read_at')))->toHaveCount(2);
    Event::assertDispatchedTimes(MessagesRead::class, 1);
});

it('deteta contactos, links e tentativas de combinar fora da plataforma', function (string $text, array $reasons) {
    expect(app(ChatFlagger::class)->reasons($text))->toBe($reasons);
})->with([
    'telefone' => ['Liga para 923 456 789', ['phone']],
    'telefone com pontos' => ['o meu é 9.2.3.4.5.6.7.8.9', ['phone']],
    'e-mail' => ['escreve para ana@exemplo.ao', ['email']],
    'link' => ['vê aqui wa.me/244923456789', ['phone', 'link']],
    'fora da plataforma' => ['Cancela o pedido e pagas-me por fora, fica mais barato sem comissão', ['off_platform']],
    'normal' => ['Chego às 10h, levo as ferramentas.', []],
]);

it('a mensagem sinalizada aparece mascarada à outra parte e com o texto original à equipa, que a revê', function () {
    chat('provider', ['body' => 'Paga-me directo pelo 923 456 789 e fica mais barato'])
        ->assertCreated()->assertJsonPath('data.body', 'Paga-me directo pelo [contacto escondido] e fica mais barato');

    $message = Message::query()->sole();
    expect($message->flagged)->toBeTrue()
        ->and($message->flag_reasons)->toBe(['phone', 'off_platform'])
        ->and($message->flagged_body)->toContain('923 456 789');

    apiLogin($this->session->client);
    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->assertJsonMissingPath('data.0.flagged_body')
        ->assertJsonPath('data.0.body', 'Paga-me directo pelo [contacto escondido] e fica mais barato');

    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Operator)->create());
    Livewire::test(ListFlaggedMessages::class)->assertCanSeeTableRecords([$message])
        ->callTableAction('review', $message, data: ['note' => 'Técnico avisado'])->assertHasNoTableActionErrors();

    expect($message->fresh()?->flag_status)->toBe(Message::FLAG_REVIEWED)
        ->and(App\Models\AuditLog::query()->where('action', 'chat.flag_reviewed')->exists())->toBeTrue();
});

it('sem servidor de tempo real, a mensagem é gravada na mesma e não dá erro', function () {
    $events = Mockery::spy(app('events'));
    $events->shouldReceive('dispatch')->with(Mockery::type(MessageSent::class))
        ->andThrow(new Illuminate\Broadcasting\BroadcastException('Servidor de tempo real desligado'));
    Event::swap($events);

    chat('client', ['body' => 'Bom dia'])->assertCreated()->assertJsonPath('data.body', 'Bom dia');

    expect(Message::query()->where('body', 'Bom dia')->exists())->toBeTrue();
    $events->shouldHaveReceived('dispatch')->with(Mockery::type(MessageSent::class));
});
