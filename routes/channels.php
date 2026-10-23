<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

// Canal privado de cada utilizador (notificações em tempo real)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// O canal público "longa.ping" serve apenas para testar a ligação ao Reverb (ver App\Events\PingBroadcast).
