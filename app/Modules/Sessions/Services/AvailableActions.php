<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Payments\Models\PaymentPhase;
use App\Modules\Payments\Services\PhasedPayments;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;

/**
 * Ações que o participante pode fazer agora (a app só mostra estes botões).
 * É uma ajuda para a interface: cada endpoint volta a validar tudo do lado do servidor.
 */
class AvailableActions
{
    /**
     * @return list<string>
     */
    public function for(ServiceRequest $session, bool $isProvider): array
    {
        $rules = config('longa.session_rules');
        $now = now();
        $start = $session->scheduled_at;
        $end = $session->ends_at ?? $start->copy()->addMinutes($session->duration_minutes);
        $contestOpen = $session->no_show_contest_until?->isFuture() ?? false;

        $phases = $session->phases()->get();
        $phases = $phases->isEmpty() ? null : $phases;

        $beforeStart = [
            'start' => $isProvider && $now->between($start->copy()->subMinutes((int) $rules['start_early_minutes']), $end),
            'cancel' => true,
            'no_show' => $now->gte($start->copy()->addMinutes((int) ($isProvider ? $rules['no_show_client_after_minutes'] : $rules['no_show_provider_after_minutes']))),
        ];

        // Por orçamento: o técnico começa pelo diagnóstico (não inicia o serviço antes de o orçamento ser aceite)
        if ($session->pricing_mode === PricingMode::Quote) {
            $beforeStart = [...$beforeStart, 'start' => false, 'diagnosis' => $isProvider];
        }

        $actions = match ($session->state) {
            RequestState::Accepted => $session->awaitingPayment()
                ? ['pay' => ! $isProvider && ($session->payment_due_at?->isFuture() ?? false)]
                : [...$beforeStart, 'on_the_way' => $isProvider],
            RequestState::OnTheWay => $beforeStart,
            RequestState::Diagnosing => ['send_quote' => $isProvider],
            RequestState::QuoteSent => ['answer_quote' => ! $isProvider],
            RequestState::QuoteAccepted => $session->awaitingPayment()
                ? ['pay' => ! $isProvider && ($session->payment_due_at?->isFuture() ?? false)]
                : ['start' => $isProvider],
            RequestState::InProgress => [
                // Pagamento por fases: o cliente paga a fase a pagar e confirma a fase paga (que não a última)
                'pay' => ! $isProvider && $phases?->contains('status', PaymentPhase::DUE),
                'confirm_phase' => ! $isProvider && $phases !== null && $phases->slice(0, -1)->contains('status', PaymentPhase::PAID),
                'finish' => $isProvider && ($phases === null || app(PhasedPayments::class)->readyToFinish($session)),
                'dispute' => true,
                // Orçamento aceite: o técnico pode propor trabalho extra (adenda)
                'addendum' => $isProvider && $session->pricing_mode === PricingMode::Quote,
            ],
            RequestState::Completed => [
                'show_qr' => $isProvider && $now->lt($end->copy()->addHours((int) $rules['qr_window_hours_after_end'])),
                'confirm' => ! $isProvider,
                'confirm_without_qr' => ! $isProvider,
                'dispute' => true,
            ],
            RequestState::Cancelled => ['dispute' => $contestOpen && match ($session->closed_reason) {
                'no_show_provider' => $isProvider,
                'no_show_client' => ! $isProvider,
                default => false,
            }],
            RequestState::Paid => ['confirm_cash' => ! $isProvider && $session->isCash() && $session->cash_confirmed_at === null],
            default => [],
        };

        return array_keys(array_filter($actions));
    }
}
