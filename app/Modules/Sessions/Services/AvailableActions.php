<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\TrainingSession;

/**
 * Ações que o participante pode fazer agora (a app só mostra estes botões).
 * É uma ajuda para a interface: cada endpoint volta a validar tudo do lado do servidor.
 */
class AvailableActions
{
    /**
     * @return list<string>
     */
    public function for(TrainingSession $session, bool $isTrainer): array
    {
        $rules = config('longa.session_rules');
        $now = now();
        $start = $session->scheduled_at;
        $end = $session->ends_at ?? $start->copy()->addMinutes($session->duration_minutes);
        $contestOpen = $session->no_show_contest_until?->isFuture() ?? false;

        $actions = match ($session->state) {
            SessionState::PendingPayment => ['pay' => ! $isTrainer && ($session->payment_due_at?->isFuture() ?? false)],
            SessionState::Scheduled => [
                'start' => $isTrainer && $now->between($start->copy()->subMinutes((int) $rules['start_early_minutes']), $end),
                'cancel' => true,
                'no_show' => $now->gte($start->copy()->addMinutes((int) ($isTrainer ? $rules['no_show_student_after_minutes'] : $rules['no_show_trainer_after_minutes']))),
            ],
            SessionState::InProgress => ['finish' => $isTrainer],
            SessionState::AwaitingConfirmation => [
                'show_qr' => $isTrainer && $now->lt($end->copy()->addHours((int) $rules['qr_window_hours_after_end'])),
                'confirm' => ! $isTrainer,
                'confirm_without_qr' => ! $isTrainer,
                'dispute' => ! $isTrainer,
            ],
            SessionState::NoShowTrainer => ['dispute' => $isTrainer && $contestOpen],
            SessionState::NoShowStudent => ['dispute' => ! $isTrainer && $contestOpen],
            SessionState::Completed => ['confirm_cash' => ! $isTrainer && $session->isCash() && $session->cash_confirmed_at === null],
            default => [],
        };

        return array_keys(array_filter($actions));
    }
}
