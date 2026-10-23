<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Policies;

use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\User;

/**
 * Só o aluno e o formador da aula a veem e usam o chat.
 */
class TrainingSessionPolicy
{
    public function view(User $user, TrainingSession $session): bool
    {
        return $session->involves($user);
    }

    public function chat(User $user, TrainingSession $session): bool
    {
        return $session->involves($user);
    }
}
