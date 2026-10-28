<?php

declare(strict_types=1);

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Canais privados (autorização em POST /api/v1/broadcasting/auth, com cookie da PWA ou Bearer token).
| Regra: só o próprio utilizador / os participantes. Nada de canais por papel ou públicos com dados.
*/

// Utilizador: ofertas TRIM TRIM (técnico), avisos de pedido aceite/perdido/cancelado
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);

// Pedido: estado da procura, só para o cliente que o publicou
Broadcast::channel('request.{requestId}', function (User $user, int $requestId) {
    return ServiceRequest::query()->whereKey($requestId)->where('client_id', $user->id)->exists();
});

// Serviço: chat entre o cliente e o técnico do serviço
Broadcast::channel('session.{sessionId}', function (User $user, int $sessionId) {
    return ServiceJob::query()
        ->whereKey($sessionId)
        ->where(fn ($q) => $q->where('client_id', $user->id)->orWhereHas('provider', fn ($t) => $t->where('user_id', $user->id)))
        ->exists();
});

// O canal público "longa.ping" serve apenas para testar a ligação ao Reverb (ver App\Events\PingBroadcast).
