<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * O cliente confirma que o serviço aconteceu sem ler o QR (ex.: esqueceu-se no fim do serviço). Só com o serviço
 * à espera de confirmação (o técnico terminou, ou regra R1/R2). Liberta o escrow; idempotente.
 * Quem confirma é sempre o cliente, que é quem pagou: não há forma de o técnico se autoconfirmar.
 */
class ConfirmWithoutQr
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ServiceRequest $session, User $client): ServiceRequest
    {
        return DB::transaction(function () use ($session, $client) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === RequestState::Paid) {
                return $locked;
            }

            if ($locked->state !== RequestState::Completed) {
                throw new ApiException('session_not_awaiting', 'O serviço ainda não está à espera de confirmação. Use o QR do técnico.', 409);
            }

            $this->lifecycle->transition($locked, RequestState::Paid, 'confirmed', $client, ['method' => 'manual'], ['confirmed_at' => now()]);
            $this->settlement->complete($locked, $client, 'client_confirmed_manually');
            SessionConfirmed::dispatch($locked);
            $this->audit->record('sessions.confirmed', $locked, ['method' => 'manual'], $client->id);

            return $locked;
        });
    }
}
