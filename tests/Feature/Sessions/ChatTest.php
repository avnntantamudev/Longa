<?php

declare(strict_types=1);

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\MessageSent;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->client = User::factory()->withRole(Role::Client)->create();
    $this->provider = ProviderProfile::factory()->verified()->create();
    $request = ServiceRequest::factory()->create(['client_id' => $this->client->id]);
    $this->session = ServiceJob::query()->create([
        'service_request_id' => $request->id, 'client_id' => $this->client->id, 'provider_profile_id' => $this->provider->id,
        'category_id' => $request->category_id, 'title' => 'Serviço de SGME', 'amount' => 25000, 'commission' => 3750, 'provider_net' => 21250,
        'scheduled_at' => now()->addDay(), 'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona',
        'latitude' => -8.9167, 'longitude' => 13.1833,
    ]);
    Conversation::query()->create(['service_job_id' => $this->session->id]);
});

it('troca mensagens de texto em tempo real entre cliente e técnico', function () {
    Event::fake([MessageSent::class]);

    apiLogin($this->client);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá Carlos! Pronto para o serviço?'])->assertCreated();

    apiLogin($this->provider->user);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Bom dia! Sim.'])->assertCreated();

    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Olá Carlos! Pronto para o serviço?')
        ->assertJsonPath('data.1.sender.id', $this->provider->user_id);

    Event::assertDispatchedTimes(MessageSent::class, 2);
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->broadcastOn()->name === "private-session.{$this->session->id}");
});

it('não duplica uma mensagem reenviada com o mesmo client_id', function () {
    apiLogin($this->client);

    $a = $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá', 'client_id' => 'c-1'])->json('data.id');
    $b = $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá', 'client_id' => 'c-1'])->json('data.id');

    expect($b)->toBe($a);
    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->assertJsonCount(1, 'data');
});

it('envia PDF para o disco privado e dá acesso por link assinado temporário', function () {
    apiLogin($this->provider->user);

    $id = $this->post("/api/v1/sessions/{$this->session->id}/messages", [
        'attachment' => UploadedFile::fake()->createWithContent('Relatorio_SGME.pdf', "%PDF-1.4\n%fake pdf\n"),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.attachment.name', 'Relatorio_SGME.pdf')
        ->json('data.id');

    $url = $this->getJson("/api/v1/messages/{$id}/attachment")->assertOk()->json('data.url');

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Sem assinatura ou com o link expirado não há acesso
    $this->get("/chat/anexos/{$id}")->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

it('recusa ficheiros que não são PDF, mesmo com a extensão .pdf', function () {
    apiLogin($this->client);

    $this->post("/api/v1/sessions/{$this->session->id}/messages", [
        'attachment' => UploadedFile::fake()->createWithContent('falso.pdf', '<?php echo "não sou um pdf";'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['attachment'], 'error.details');

    $this->post("/api/v1/sessions/{$this->session->id}/messages", [
        'attachment' => UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('esconde telefones e e-mails escritos no chat', function () {
    apiLogin($this->client);

    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Liga-me: +244 923 456 789 ou 923-111-222, ou maria@exemplo.ao'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Liga-me: [contacto escondido] ou [contacto escondido], ou [contacto escondido]');
});

it('só os participantes veem o serviço e o chat', function () {
    apiLogin(ProviderProfile::factory()->create()->user);

    $this->getJson("/api/v1/sessions/{$this->session->id}")->assertForbidden();
    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->assertForbidden();
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'intruso'])->assertForbidden();
});

it('o cliente vê os dados do técnico e o técnico vê o local exato e quanto recebe', function () {
    apiLogin($this->client);
    $asClient = $this->getJson("/api/v1/sessions/{$this->session->id}")->assertOk()->json('data');
    expect($asClient['provider']['name'])->toBe($this->provider->user->name)
        ->and($asClient)->not->toHaveKeys(['provider_net', 'commission'])
        ->and(json_encode($asClient))->not->toContain($this->provider->user->phone);

    apiLogin($this->provider->user);
    $asProvider = $this->getJson("/api/v1/sessions/{$this->session->id}")->assertOk()->json('data');
    expect($asProvider['provider_net'])->toBe(21250)
        ->and($asProvider['latitude'])->toBe(-8.9167)
        ->and($asProvider['client']['name'])->toBe($this->client->name)
        ->and(json_encode($asProvider))->not->toContain($this->client->phone);
});
