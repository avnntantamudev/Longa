<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Policies;

use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;

/**
 * Só o cliente e o técnico do serviço o veem e usam o chat.
 */
class ServiceJobPolicy
{
    public function view(User $user, ServiceJob $session): bool
    {
        return $session->involves($user);
    }

    public function chat(User $user, ServiceJob $session): bool
    {
        return $session->involves($user);
    }

    /** Só o cliente do serviço pago, confirma o serviço, disputa ou reporta a falta do técnico */
    public function pay(User $user, ServiceJob $session): bool
    {
        return $session->client_id === $user->id;
    }

    public function actAsClient(User $user, ServiceJob $session): bool
    {
        return $session->client_id === $user->id;
    }

    /** Só o técnico do serviço o inicia, termina, mostra o QR ou reporta a falta do cliente */
    public function actAsProvider(User $user, ServiceJob $session): bool
    {
        return $session->provider->user_id === $user->id;
    }
}
