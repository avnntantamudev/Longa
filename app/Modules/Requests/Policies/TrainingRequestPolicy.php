<?php

declare(strict_types=1);

namespace App\Modules\Requests\Policies;

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\User;

/**
 * Só o aluno que publicou vê e gere o pedido.
 */
class TrainingRequestPolicy
{
    public function view(User $user, TrainingRequest $request): bool
    {
        return $request->student_id === $user->id;
    }

    public function cancel(User $user, TrainingRequest $request): bool
    {
        return $request->student_id === $user->id;
    }

    public function republish(User $user, TrainingRequest $request): bool
    {
        return $request->student_id === $user->id;
    }
}
