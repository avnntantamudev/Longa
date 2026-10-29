<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * O técnico marca que está a caminho (aceite → a caminho); o cliente é avisado. Não é obrigatório: iniciar o
 * serviço ou o diagnóstico passa por "a caminho" se o técnico não o tiver marcado.
 */
class MarkOnTheWay
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    public function handle(ServiceRequest $session, User $provider): ServiceRequest
    {
        $result = DB::transaction(function () use ($session, $provider) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === RequestState::OnTheWay) {
                return ['session' => $locked, 'new' => false];
            }
            if ($locked->awaitingPayment()) {
                throw new ApiException('payment_pending', 'O cliente ainda não pagou este serviço.', 409);
            }
            if ($locked->state !== RequestState::Accepted) {
                throw new ApiException('session_not_startable', 'Este serviço já não está à espera do técnico ('.$locked->state->label().').', 409);
            }

            $this->lifecycle->transition($locked, RequestState::OnTheWay, 'on_the_way', $provider, [], ['on_the_way_at' => now()]);

            return ['session' => $locked, 'new' => true];
        });

        if ($result['new']) {
            $result['session']->client->notify(new SessionUpdateNotification($result['session'], SessionUpdateNotification::ON_THE_WAY));
        }

        return $result['session'];
    }
}
