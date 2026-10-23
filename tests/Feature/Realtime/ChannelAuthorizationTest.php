<?php

declare(strict_types=1);

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;

beforeEach(function () {
    // Broadcaster real (Reverb/Pusher) para a autorização assinar a resposta; nada é enviado
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // Os canais ficam registados no driver criado no arranque (null nos testes): recriar e registar de novo
    app(Illuminate\Broadcasting\BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
});

function authorizeChannel(string $channel): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
}

function sessionBetween(User $student, TrainerProfile $trainer): TrainingSession
{
    $request = TrainingRequest::factory()->create(['student_id' => $student->id]);

    return TrainingSession::query()->create([
        'training_request_id' => $request->id, 'student_id' => $student->id, 'trainer_profile_id' => $trainer->id,
        'skill_id' => $request->skill_id, 'title' => 'Aula', 'amount' => 25000, 'commission' => 3750, 'trainer_net' => 21250,
        'scheduled_at' => now()->addDay(), 'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona',
        'latitude' => -8.9167, 'longitude' => 13.1833,
    ]);
}

it('exige autenticação', function () {
    authorizeChannel('user.1')->assertUnauthorized();
});

it('autoriza só o próprio canal de utilizador', function () {
    $user = apiLogin(User::factory()->create());

    authorizeChannel("user.{$user->id}")->assertOk()->assertJsonStructure(['auth']);
    authorizeChannel('user.'.($user->id + 1))->assertForbidden();
});

it('autoriza o canal do pedido só ao aluno que o publicou', function () {
    $request = TrainingRequest::factory()->create();

    apiLogin($request->student);
    authorizeChannel("request.{$request->id}")->assertOk();

    apiLogin(TrainerProfile::factory()->create()->user);
    authorizeChannel("request.{$request->id}")->assertForbidden();
});

it('autoriza o canal da aula só ao aluno e ao formador da aula', function () {
    $student = User::factory()->create();
    $trainer = TrainerProfile::factory()->create();
    $session = sessionBetween($student, $trainer);

    apiLogin($student);
    authorizeChannel("session.{$session->id}")->assertOk();

    apiLogin($trainer->user);
    authorizeChannel("session.{$session->id}")->assertOk();

    apiLogin(TrainerProfile::factory()->create()->user);
    authorizeChannel("session.{$session->id}")->assertForbidden();
});
