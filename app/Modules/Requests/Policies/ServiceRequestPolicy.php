<?php

declare(strict_types=1);

namespace App\Modules\Requests\Policies;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;

/**
 * Só o aluno que publicou vê e gere o pedido.
 */
class ServiceRequestPolicy
{
    public function view(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    public function cancel(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }

    public function republish(User $user, ServiceRequest $request): bool
    {
        return $request->client_id === $user->id;
    }
}
