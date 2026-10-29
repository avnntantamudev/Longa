<?php

declare(strict_types=1);

namespace App\Modules\Requests\Policies;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;

/**
 * O cliente vê e gere o pedido; depois de aceite, o técnico atribuído também o vê e executa.
 */
class ServiceRequestPolicy
{
    public function view(User $user, ServiceRequest $request): bool
    {
        return $request->involves($user);
    }

    public function cancel(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    public function republish(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    public function chat(User $user, ServiceRequest $request): bool
    {
        return $request->provider_profile_id !== null && $request->involves($user);
    }

    /** Só o cliente paga, confirma o serviço, disputa ou reporta a falta do técnico */
    public function pay(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    public function actAsClient(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    /** Só o técnico atribuído marca a caminho, inicia, conclui, mostra o QR ou reporta a falta do cliente */
    public function actAsProvider(User $user, ServiceRequest $request): bool
    {
        return $request->provider_profile_id !== null && $request->provider->user_id === $user->id;
    }
}
