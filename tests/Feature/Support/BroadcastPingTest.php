<?php

declare(strict_types=1);

use App\Events\PingBroadcast;
use Illuminate\Support\Facades\Event;

it('envia o evento de teste no canal público longa.ping', function () {
    Event::fake([PingBroadcast::class]);

    $this->artisan('longa:ping', ['message' => 'olá'])->assertSuccessful();

    Event::assertDispatched(PingBroadcast::class, function (PingBroadcast $event) {
        return $event->broadcastOn()->name === 'longa.ping'
            && $event->broadcastAs() === 'ping'
            && $event->broadcastWith()['message'] === 'olá';
    });
});
