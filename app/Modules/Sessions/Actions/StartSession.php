<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * O técnico inicia o serviço (a partir de 15 min antes da hora marcada até ao fim previsto).
 * Idempotente: iniciar um serviço já em curso devolve-a sem erro.
 */
class StartSession
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    public function handle(ServiceRequest $session, User $provider): ServiceRequest
    {
        return DB::transaction(function () use ($session, $provider) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === RequestState::InProgress) {
                return $locked;
            }

            if ($locked->awaitingPayment()) {
                throw new ApiException('payment_pending', 'O cliente ainda não pagou este serviço.', 409);
            }

            if (! in_array($locked->state, [RequestState::Accepted, RequestState::OnTheWay, RequestState::QuoteAccepted], true)) {
                throw new ApiException('session_not_startable', 'Este serviço não pode ser iniciado no estado atual ('.$locked->state->label().').', 409);
            }

            $afterQuote = $locked->state === RequestState::QuoteAccepted;
            $earliest = $locked->scheduled_at->copy()->subMinutes((int) config('longa.session_rules.start_early_minutes'));
            if (! $afterQuote && now()->lt($earliest)) {
                throw new ApiException('session_too_early', 'Só pode iniciar o serviço a partir de '.$earliest->format('H:i').'.', 409);
            }

            if (! $afterQuote && $locked->ends_at !== null && now()->gt($locked->ends_at)) {
                throw new ApiException('session_too_late', 'A hora prevista do serviço já terminou.', 409);
            }

            if ($locked->state === RequestState::Accepted) {
                $this->lifecycle->transition($locked, RequestState::OnTheWay, 'on_the_way', $provider, [], ['on_the_way_at' => now()]);
            }
            $attributes = ['started_at' => now()];
            if ($afterQuote) {
                $days = (int) $locked->quotes()->where('status', Quote::ACCEPTED)->value('execution_days');
                $attributes['ends_at'] = now()->addDays(max(1, $days));
                EscrowHold::query()->where('service_request_id', $locked->id)->update([
                    'expires_at' => $attributes['ends_at']->copy()->addDays((int) config('longa.session_rules.hold_max_days')),
                ]);
            }
            $this->lifecycle->transition($locked, RequestState::InProgress, 'started', $provider, [], $attributes);
            SessionStarted::dispatch($locked);

            return $locked;
        });
    }
}
