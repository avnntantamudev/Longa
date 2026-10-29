<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Notifications\Notifications\SessionReminderNotification;
use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\QuoteFlow;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Models\SessionEvent;
use App\Support\StateMachine\InvalidTransition;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Regras automáticas do serviço, todas aqui e configuradas em config/longa.php → session_rules
 * (docs/state-machines.md). Corridas por `php artisan longa:sessions:tick` (scheduler, a cada minuto).
 * Atuam sobre o estado único do pedido (os motivos de cancelamento ficam em closed_reason).
 * Cada regra é idempotente: bloqueia o serviço, volta a verificar o estado e usa transições condicionais.
 */
class SessionTimeoutRules
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly RefundPolicy $policy,
        private readonly ScoreRecorder $score,
        private readonly ConfirmCashPayment $confirmCash,
        private readonly QuoteFlow $quotes,
    ) {}

    /** Motivos de cancelamento com prazo de contestação antes de mexer no dinheiro (R1 e R4) */
    private const CONTESTABLE = ['no_show_provider', 'no_show_client', 'not_started'];

    /**
     * @return array<string, int> número de serviços afetados por regra
     */
    public function run(): array
    {
        return [
            'R3_payment_expired' => $this->expireUnpaid(),
            'quotes_expired' => $this->quotes->expireDue(),
            'R1_not_started' => $this->cancelNotStarted(),
            'R2_no_qr' => $this->completeWithoutQr(),
            'reminders_before' => $this->remindUpcoming(),
            'reminders' => $this->remindPendingConfirmations(),
            'R2_window_closed' => $this->closeConfirmationWindows(),
            'R4_no_show_settled' => $this->settleUncontestedNoShows(),
            'R5_cash_auto_confirmed' => $this->autoConfirmCash(),
        ];
    }

    /**
     * R3: pedido aceite à espera do pagamento digital com o prazo vencido → cancelado (técnico libertado).
     */
    public function expireUnpaid(): int
    {
        return $this->each(
            fn (Builder $q) => $q->where('state', RequestState::Accepted->value)
                ->where('payment_status', ServiceRequest::PAYMENT_PENDING)
                ->where('payment_due_at', '<=', now()),
            function (ServiceRequest $s) {
                $this->lifecycle->transition($s, RequestState::Cancelled, 'payment_window_expired', null, ['rule' => 'R3'], [
                    'payment_status' => ServiceRequest::PAYMENT_FAILED, 'closed_reason' => 'payment_failed', 'cancelled_at' => now(),
                ]);
            },
            fn (ServiceRequest $s) => $s->awaitingPayment(),
        );
    }

    /**
     * R1: o técnico nunca marcou o início e já passaram N horas do fim previsto → cancelado. O técnico pode
     * contestar no prazo das faltas; sem contestação o cliente é reembolsado (R4).
     */
    public function cancelNotStarted(): int
    {
        $after = now()->subHours((int) config('longa.session_rules.auto_awaiting_after_end_hours'));

        return $this->each(
            fn (Builder $q) => $q->whereIn('state', [RequestState::Accepted->value, RequestState::OnTheWay->value])
                ->where(fn ($p) => $p->whereNull('payment_status')->orWhere('payment_status', ServiceRequest::PAYMENT_HELD))
                ->where('ends_at', '<=', $after),
            function (ServiceRequest $s) {
                $contestUntil = now()->addHours((int) config('longa.session_rules.no_show_contest_hours'));
                $this->lifecycle->transition($s, RequestState::Cancelled, 'auto_not_started', null, ['rule' => 'R1', 'contest_until' => $contestUntil->toIso8601String()], [
                    'closed_reason' => 'not_started', 'no_show_contest_until' => $contestUntil, 'cancelled_at' => now(),
                ]);
            },
            fn (ServiceRequest $s) => in_array($s->state, [RequestState::Accepted, RequestState::OnTheWay], true) && ! $s->awaitingPayment(),
        );
    }

    /**
     * R2: iniciado e não concluído N horas após o fim previsto → concluído (começa a janela de confirmação).
     */
    public function completeWithoutQr(): int
    {
        $after = now()->subHours((int) config('longa.session_rules.auto_awaiting_after_end_hours'));

        return $this->each(
            fn (Builder $q) => $q->where('state', RequestState::InProgress->value)->where('ends_at', '<=', $after),
            function (ServiceRequest $s) {
                $this->lifecycle->transition($s, RequestState::Completed, 'auto_awaiting', null, ['rule' => 'R2', 'reason' => 'no_qr'], [
                    'completed_at' => now(), 'ended_at' => $s->ended_at ?? $s->ends_at,
                ]);
            },
            fn (ServiceRequest $s) => $s->state === RequestState::InProgress,
        );
    }

    /**
     * Lembretes ao cliente e ao técnico antes de um serviço marcado (24 h e 1 h antes; só se o técnico aceitou
     * antes desse momento). Cada lembrete é enviado uma vez.
     */
    public function remindUpcoming(): int
    {
        $sent = 0;
        foreach (['24h' => 24, '1h' => 1] as $label => $hours) {
            $requests = ServiceRequest::query()
                ->whereIn('state', [RequestState::Accepted->value, RequestState::OnTheWay->value])
                ->where('scheduled_at', '>', now())
                ->where('scheduled_at', '<=', now()->addHours($hours))
                ->whereRaw('matched_at <= DATE_SUB(scheduled_at, INTERVAL ? HOUR)', [$hours])
                ->whereDoesntHave('events', fn ($q) => $q->where('type', "reminder_{$label}"))
                ->with(['client', 'provider.user'])
                ->limit(200)
                ->get();

            foreach ($requests as $request) {
                // O lembrete de 1 h torna o de 24 h desnecessário
                if ($label === '24h' && $request->scheduled_at->lte(now()->addHour())) {
                    continue;
                }
                $this->lifecycle->record($request, "reminder_{$label}");
                $request->client->notify(new SessionUpdateNotification($request, SessionUpdateNotification::REMINDER, $label));
                $request->provider->user->notify(new SessionUpdateNotification($request, SessionUpdateNotification::REMINDER, $label));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Lembrete ao cliente a meio da janela de confirmação (uma vez por serviço).
     */
    public function remindPendingConfirmations(): int
    {
        $window = (int) config('longa.session_rules.confirmation_window_hours');
        $remindAt = now()->subHours(intdiv($window, 2));

        $sessions = ServiceRequest::query()
            ->where('state', RequestState::Completed->value)
            ->where('completed_at', '<=', $remindAt)
            ->whereDoesntHave('events', fn ($q) => $q->where('type', 'reminder_sent'))
            ->with('client')
            ->limit(200)
            ->get();

        foreach ($sessions as $session) {
            $this->lifecycle->record($session, 'reminder_sent', null, ['hours_left' => intdiv($window, 2)]);
            $session->client->notify(new SessionReminderNotification($session, intdiv($window, 2)));
        }

        return $sessions->count();
    }

    /**
     * Fim da janela de confirmação sem resposta nem disputa do cliente → pago (libertação para o técnico).
     */
    public function closeConfirmationWindows(): int
    {
        $before = now()->subHours((int) config('longa.session_rules.confirmation_window_hours'));

        return $this->each(
            fn (Builder $q) => $q->where('state', RequestState::Completed->value)->where('completed_at', '<=', $before),
            function (ServiceRequest $s) {
                $this->lifecycle->transition($s, RequestState::Paid, 'auto_completed', null, ['rule' => 'R2', 'reason' => 'no_dispute_in_window'], ['confirmed_at' => now()]);
                $this->settlement->complete($s, null, 'auto_no_dispute');
                SessionConfirmed::dispatch($s);
            },
            fn (ServiceRequest $s) => $s->state === RequestState::Completed,
        );
    }

    /**
     * R4 (e R1): falta ou serviço não iniciado sem contestação no prazo → liquidação conforme a política.
     */
    public function settleUncontestedNoShows(): int
    {
        return $this->each(
            fn (Builder $q) => $q->where('state', RequestState::Cancelled->value)
                ->whereIn('closed_reason', self::CONTESTABLE)
                ->where('no_show_contest_until', '<=', now())
                ->whereDoesntHave('disputes')
                ->whereDoesntHave('events', fn ($e) => $e->where('type', 'no_show_settled')),
            function (ServiceRequest $s) {
                $providerFault = $s->closed_reason !== 'no_show_client';
                $providerGross = match ($s->closed_reason) {
                    'no_show_client' => $this->policy->clientNoShow($s),
                    'no_show_provider' => $this->policy->providerFault($s),
                    default => 0,
                };

                $this->lifecycle->record($s, 'no_show_settled', null, ['rule' => $s->closed_reason === 'not_started' ? 'R1' : 'R4', 'provider_share' => $providerGross]);
                if (! $s->isCash() && EscrowHold::query()->where('service_request_id', $s->id)->whereIn('state', EscrowHold::ACTIVE)->exists()) {
                    $this->settlement->split($s, $providerGross, null, (string) $s->closed_reason);
                }
                if ($providerFault) {
                    $this->score->record((int) $s->provider_profile_id, ScoreEvent::NO_SHOW, $s);
                }
            },
            fn (ServiceRequest $s) => $s->state === RequestState::Cancelled && in_array($s->closed_reason, self::CONTESTABLE, true)
                && ! $s->disputes()->exists()
                && ! SessionEvent::query()->where('service_request_id', $s->id)->where('type', 'no_show_settled')->exists(),
        );
    }

    /**
     * R5: serviço em dinheiro concluído e o cliente não confirmou o pagamento em 48 h → assume-se pago
     * (o serviço aconteceu, confirmado por QR) e regista-se a comissão em dívida. O técnico pode disputar.
     */
    public function autoConfirmCash(): int
    {
        $before = now()->subHours((int) config('longa.session_rules.confirmation_window_hours'));
        $sessions = ServiceRequest::query()
            ->where('state', RequestState::Paid->value)
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
     * Aplica $apply a cada serviço selecionado, numa transação própria, com o serviço bloqueado e a condição
     * verificada outra vez (outro processo pode ter mudado o estado entretanto).
     *
     * @param  Closure(Builder<ServiceRequest>): Builder<ServiceRequest>  $select
     * @param  Closure(ServiceRequest): void  $apply
     * @param  Closure(ServiceRequest): bool  $stillApplies
     */
    private function each(Closure $select, Closure $apply, Closure $stillApplies): int
    {
        $count = 0;
        $ids = $select(ServiceRequest::query())->limit(200)->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $apply, $stillApplies, &$count) {
                    /** @var ServiceRequest $session */
                    $session = ServiceRequest::query()->lockForUpdate()->findOrFail($id);
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
