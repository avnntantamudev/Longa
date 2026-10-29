<?php

declare(strict_types=1);

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\ProviderProfile;
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

function sessionBetween(User $client, ProviderProfile $provider): ServiceRequest
{
    return acceptedSession('electronic', ['client_id' => $client->id, 'provider_profile_id' => $provider->id]);
}

it('exige autenticação', function () {
    authorizeChannel('user.1')->assertUnauthorized();
});

it('autoriza só o próprio canal de utilizador', function () {
    $user = apiLogin(User::factory()->create());

    authorizeChannel("user.{$user->id}")->assertOk()->assertJsonStructure(['auth']);
    authorizeChannel('user.'.($user->id + 1))->assertForbidden();
});

it('autoriza o canal do pedido só ao cliente que o publicou', function () {
    $request = ServiceRequest::factory()->create();

    apiLogin($request->client);
    authorizeChannel("request.{$request->id}")->assertOk();

    apiLogin(ProviderProfile::factory()->create()->user);
    authorizeChannel("request.{$request->id}")->assertForbidden();
});

it('autoriza o canal do serviço só ao cliente e ao técnico do serviço', function () {
    $client = User::factory()->create();
    $provider = ProviderProfile::factory()->create();
    $session = sessionBetween($client, $provider);

    apiLogin($client);
    authorizeChannel("session.{$session->id}")->assertOk();

    apiLogin($provider->user);
    authorizeChannel("session.{$session->id}")->assertOk();

    apiLogin(ProviderProfile::factory()->create()->user);
    authorizeChannel("session.{$session->id}")->assertForbidden();
});
