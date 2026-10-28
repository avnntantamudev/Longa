<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * O aluno confirma que a aula aconteceu sem ler o QR (ex.: esqueceu-se no fim da aula). Só com a aula
 * à espera de confirmação (o formador terminou, ou regra R1/R2). Liberta o escrow; idempotente.
 * Quem confirma é sempre o aluno, que é quem pagou: não há forma de o formador se autoconfirmar.
 */
class ConfirmWithoutQr
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ServiceJob $session, User $client): ServiceJob
    {
        return DB::transaction(function () use ($session, $client) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === SessionState::Completed) {
                return $locked;
            }

            if ($locked->state !== SessionState::AwaitingConfirmation) {
                throw new ApiException('session_not_awaiting', 'A aula ainda não está à espera de confirmação. Use o QR do formador.', 409);
            }

            $this->lifecycle->transition($locked, SessionState::Completed, 'confirmed', $client, ['method' => 'manual'], ['confirmed_at' => now()]);
            $this->settlement->complete($locked, $client, 'client_confirmed_manually');
            SessionConfirmed::dispatch($locked);
            $this->audit->record('sessions.confirmed', $locked, ['method' => 'manual'], $client->id);

            return $locked;
        });
    }
}
