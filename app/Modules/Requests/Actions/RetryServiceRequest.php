<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;

/**
 * Nova tentativa do mesmo pedido (sem técnico disponível → à procura de técnico). Para mudar a data ou o valor,
 * o cliente republica (pedido novo).
 */
class RetryServiceRequest
{
    public function __construct(private readonly MatchingCoordinator $matching, private readonly AuditLogger $audit) {}

    public function handle(ServiceRequest $request, User $client): ServiceRequest
    {
        if ($request->state !== RequestState::NoProvider) {
            throw new ApiException('request_not_retryable', 'Só pode tentar de novo um pedido sem técnico disponível.', 409);
        }

        $lead = (int) config('longa.requests.min_lead_minutes');
        if ($request->scheduled_at->lt(now()->addMinutes($lead))) {
            throw new ApiException('request_date_passed', 'A data do pedido já passou. Republique com uma nova data.', 409);
        }

        $this->matching->retry($request->by($client));
        $this->audit->record('requests.retried', $request, [], $client->id);

        return $request->refresh();
    }
}
