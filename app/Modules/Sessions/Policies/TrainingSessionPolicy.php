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

    /** Só o aluno da aula paga, confirma a aula, disputa ou reporta a falta do formador */
    public function pay(User $user, TrainingSession $session): bool
    {
        return $session->student_id === $user->id;
    }

    public function actAsStudent(User $user, TrainingSession $session): bool
    {
        return $session->student_id === $user->id;
    }

    /** Só o formador da aula a inicia, termina, mostra o QR ou reporta a falta do aluno */
    public function actAsTrainer(User $user, TrainingSession $session): bool
    {
        return $session->trainer->user_id === $user->id;
    }
}
