<?php

declare(strict_types=1);

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Events\MessageSent;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->student = User::factory()->withRole(Role::Student)->create();
    $this->trainer = TrainerProfile::factory()->verified()->create();
    $request = TrainingRequest::factory()->create(['student_id' => $this->student->id]);
    $this->session = TrainingSession::query()->create([
        'training_request_id' => $request->id, 'student_id' => $this->student->id, 'trainer_profile_id' => $this->trainer->id,
        'skill_id' => $request->skill_id, 'title' => 'Aula de SGME', 'amount' => 25000, 'commission' => 3750, 'trainer_net' => 21250,
        'scheduled_at' => now()->addDay(), 'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona',
        'latitude' => -8.9167, 'longitude' => 13.1833,
    ]);
    Conversation::query()->create(['training_session_id' => $this->session->id]);
});

it('troca mensagens de texto em tempo real entre aluno e formador', function () {
    Event::fake([MessageSent::class]);

    apiLogin($this->student);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá Carlos! Pronto para a aula?'])->assertCreated();

    apiLogin($this->trainer->user);
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Bom dia! Sim.'])->assertCreated();

    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Olá Carlos! Pronto para a aula?')
        ->assertJsonPath('data.1.sender.id', $this->trainer->user_id);

    Event::assertDispatchedTimes(MessageSent::class, 2);
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->broadcastOn()->name === "private-session.{$this->session->id}");
});

it('não duplica uma mensagem reenviada com o mesmo client_id', function () {
    apiLogin($this->student);

    $a = $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá', 'client_id' => 'c-1'])->json('data.id');
    $b = $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Olá', 'client_id' => 'c-1'])->json('data.id');

    expect($b)->toBe($a);
    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->assertJsonCount(1, 'data');
});

it('envia PDF para o disco privado e dá acesso por link assinado temporário', function () {
    apiLogin($this->trainer->user);

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
    apiLogin($this->student);

    $this->post("/api/v1/sessions/{$this->session->id}/messages", [
        'attachment' => UploadedFile::fake()->createWithContent('falso.pdf', '<?php echo "não sou um pdf";'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['attachment'], 'error.details');

    $this->post("/api/v1/sessions/{$this->session->id}/messages", [
        'attachment' => UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('esconde telefones e e-mails escritos no chat', function () {
    apiLogin($this->student);

    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'Liga-me: +244 923 456 789 ou 923-111-222, ou maria@exemplo.ao'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Liga-me: [contacto escondido] ou [contacto escondido], ou [contacto escondido]');
});

it('só os participantes veem a aula e o chat', function () {
    apiLogin(TrainerProfile::factory()->create()->user);

    $this->getJson("/api/v1/sessions/{$this->session->id}")->assertForbidden();
    $this->getJson("/api/v1/sessions/{$this->session->id}/messages")->assertForbidden();
    $this->postJson("/api/v1/sessions/{$this->session->id}/messages", ['body' => 'intruso'])->assertForbidden();
});

it('o aluno vê os dados do formador e o formador vê o local exato e quanto recebe', function () {
    apiLogin($this->student);
    $asStudent = $this->getJson("/api/v1/sessions/{$this->session->id}")->assertOk()->json('data');
    expect($asStudent['trainer']['name'])->toBe($this->trainer->user->name)
        ->and($asStudent)->not->toHaveKeys(['trainer_net', 'commission'])
        ->and(json_encode($asStudent))->not->toContain($this->trainer->user->phone);

    apiLogin($this->trainer->user);
    $asTrainer = $this->getJson("/api/v1/sessions/{$this->session->id}")->assertOk()->json('data');
    expect($asTrainer['trainer_net'])->toBe(21250)
        ->and($asTrainer['latitude'])->toBe(-8.9167)
        ->and($asTrainer['student']['name'])->toBe($this->student->name)
        ->and(json_encode($asTrainer))->not->toContain($this->student->phone);
});
