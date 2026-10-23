<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Notifications\Notifications\SessionReminderNotification;
use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Sessions\Models\TrainingSession;
use App\Support\StateMachine\InvalidTransition;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Regras automáticas da aula, todas aqui e configuradas em config/longa.php → session_rules
 * (docs/state-machines.md). Corridas por `php artisan longa:sessions:tick` (scheduler, a cada minuto).
 * Cada regra é idempotente: bloqueia a aula, volta a verificar o estado e usa transições condicionais.
 */
class SessionTimeoutRules
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly RefundPolicy $policy,
        private readonly ScoreRecorder $score,
        private readonly ConfirmCashPayment $confirmCash,
    ) {}

    /**
     * @return array<string, int> número de aulas afetadas por regra
     */
    public function run(): array
    {
        return [
            'R3_payment_expired' => $this->expireUnpaid(),
            'R1_not_started' => $this->moveToAwaiting(SessionState::Scheduled, 'not_started'),
            'R2_no_qr' => $this->moveToAwaiting(SessionState::InProgress, 'no_qr'),
            'reminders' => $this->remindPendingConfirmations(),
            'R1R2_window_closed' => $this->closeConfirmationWindows(),
            'R4_no_show_settled' => $this->settleUncontestedNoShows(),
            'R5_cash_auto_confirmed' => $this->autoConfirmCash(),
        ];
    }

    /**
     * R3: aula à espera de pagamento com o prazo vencido → payment_failed (formador libertado).
     */
    public function expireUnpaid(): int
    {
        return $this->each(
            fn (Builder $q) => $q->where('state', SessionState::PendingPayment->value)->where('payment_due_at', '<=', now()),
            function (TrainingSession $s) {
                $this->lifecycle->transition($s, SessionState::PaymentFailed, 'payment_window_expired', null, ['rule' => 'R3']);
            },
            fn (TrainingSession $s) => $s->state === SessionState::PendingPayment,
        );
    }

    /**
     * R1 (nunca iniciada) e R2 (iniciada sem QR): N horas após o fim previsto → awaiting_confirmation.
     */
    public function moveToAwaiting(SessionState $from, string $reason): int
    {
        $after = now()->subHours((int) config('longa.session_rules.auto_awaiting_after_end_hours'));

        return $this->each(
            fn (Builder $q) => $q->where('state', $from->value)->where('ends_at', '<=', $after),
            function (TrainingSession $s) use ($reason) {
                $this->lifecycle->transition($s, SessionState::AwaitingConfirmation, 'auto_awaiting', null, [
                    'rule' => $reason === 'not_started' ? 'R1' : 'R2', 'reason' => $reason,
                ], ['awaiting_since' => now(), 'ended_at' => $s->ended_at ?? $s->ends_at]);
            },
            fn (TrainingSession $s) => $s->state === $from,
        );
    }

    /**
     * Lembrete ao aluno a meio da janela de confirmação (uma vez por aula).
     */
    public function remindPendingConfirmations(): int
    {
        $window = (int) config('longa.session_rules.confirmation_window_hours');
        $remindAt = now()->subHours(intdiv($window, 2));

        $sessions = TrainingSession::query()
            ->where('state', SessionState::AwaitingConfirmation->value)
            ->where('awaiting_since', '<=', $remindAt)
            ->whereDoesntHave('events', fn ($q) => $q->where('type', 'reminder_sent'))
            ->with('student')
            ->limit(200)
            ->get();

        foreach ($sessions as $session) {
            $this->lifecycle->record($session, 'reminder_sent', null, ['hours_left' => intdiv($window, 2)]);
            $session->student->notify(new SessionReminderNotification($session, intdiv($window, 2)));
        }

        return $sessions->count();
    }

    /**
     * Fim da janela de confirmação sem resposta do aluno:
     *  - aula nunca iniciada (R1) → refunded (reembolso total);
     *  - aula iniciada (R2) → completed (libertação para o formador).
     */
    public function closeConfirmationWindows(): int
    {
        $before = now()->subHours((int) config('longa.session_rules.confirmation_window_hours'));

        return $this->each(
            fn (Builder $q) => $q->where('state', SessionState::AwaitingConfirmation->value)->where('awaiting_since', '<=', $before),
            function (TrainingSession $s) {
                if ($s->started_at === null) {
                    $this->lifecycle->transition($s, SessionState::Refunded, 'auto_refunded', null, ['rule' => 'R1', 'reason' => 'not_started_no_confirmation']);
                    $this->settlement->split($s, 0, null, 'auto_not_started');
                } else {
                    $this->lifecycle->transition($s, SessionState::Completed, 'auto_completed', null, ['rule' => 'R2', 'reason' => 'no_dispute_in_window'], ['confirmed_at' => now()]);
                    $this->settlement->complete($s, null, 'auto_no_dispute');
                }
            },
            fn (TrainingSession $s) => $s->state === SessionState::AwaitingConfirmation,
        );
    }

    /**
     * R4: falta reportada sem contestação no prazo → liquidação conforme a política.
     */
    public function settleUncontestedNoShows(): int
    {
        return $this->each(
            fn (Builder $q) => $q->whereIn('state', [SessionState::NoShowTrainer->value, SessionState::NoShowStudent->value])
                ->where('no_show_contest_until', '<=', now())
                ->whereDoesntHave('events', fn ($e) => $e->where('type', 'no_show_settled')),
            function (TrainingSession $s) {
                $trainerFault = $s->state === SessionState::NoShowTrainer;
                $trainerGross = $trainerFault ? $this->policy->trainerFault($s) : $this->policy->studentNoShow($s);

                $this->lifecycle->record($s, 'no_show_settled', null, ['rule' => 'R4', 'trainer_share' => $trainerGross]);
                if (! $s->isCash() && EscrowHold::query()->where('training_session_id', $s->id)->whereIn('state', EscrowHold::ACTIVE)->exists()) {
                    $this->settlement->split($s, $trainerGross, null, $trainerFault ? 'no_show_trainer' : 'no_show_student');
                }
                if ($trainerFault) {
                    $this->score->record($s->trainer_profile_id, ScoreEvent::NO_SHOW, $s);
                }
            },
            fn (TrainingSession $s) => in_array($s->state, [SessionState::NoShowTrainer, SessionState::NoShowStudent], true)
                && ! SessionEvent::query()->where('training_session_id', $s->id)->where('type', 'no_show_settled')->exists(),
        );
    }

    /**
     * R5: aula em dinheiro concluída e o aluno não confirmou o pagamento em 48 h → assume-se pago
     * (a aula aconteceu, confirmada por QR) e regista-se a comissão em dívida. O formador pode disputar.
     */
    public function autoConfirmCash(): int
    {
        $before = now()->subHours((int) config('longa.session_rules.confirmation_window_hours'));
        $sessions = TrainingSession::query()
            ->where('state', SessionState::Completed->value)
            ->where('payment_method', 'cash')
            ->whereNull('cash_confirmed_at')
            ->where('confirmed_at', '<=', $before)
            ->limit(200)
            ->get();

        foreach ($sessions as $session) {
            $this->confirmCash->handle($session, null, 'auto_after_window');
        }

        return $sessions->count();
    }

    /**
     * Aplica $apply a cada aula selecionada, numa transação própria, com a aula bloqueada e a condição
     * verificada outra vez (outro processo pode ter mudado o estado entretanto).
     *
     * @param  Closure(Builder<TrainingSession>): Builder<TrainingSession>  $select
     * @param  Closure(TrainingSession): void  $apply
     * @param  Closure(TrainingSession): bool  $stillApplies
     */
    private function each(Closure $select, Closure $apply, Closure $stillApplies): int
    {
        $count = 0;
        $ids = $select(TrainingSession::query())->limit(200)->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $apply, $stillApplies, &$count) {
                    /** @var TrainingSession $session */
                    $session = TrainingSession::query()->lockForUpdate()->findOrFail($id);
                    if ($stillApplies($session)) {
                        $apply($session);
                        $count++;
                    }
                });
            } catch (InvalidTransition) {
                // Mudou de estado entretanto
            } catch (Throwable $e) {
                Log::error('session_rules.failed', ['session_id' => $id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
