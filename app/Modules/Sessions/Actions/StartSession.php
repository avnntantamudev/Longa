<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * O formador inicia a aula (a partir de 15 min antes da hora marcada até ao fim previsto).
 * Idempotente: iniciar uma aula já em curso devolve-a sem erro.
 */
class StartSession
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    public function handle(ServiceJob $session, User $provider): ServiceJob
    {
        return DB::transaction(function () use ($session, $provider) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->state === SessionState::InProgress) {
                return $locked;
            }

            if ($locked->state !== SessionState::Scheduled) {
                throw new ApiException('session_not_startable', 'Esta aula não pode ser iniciada no estado atual ('.$locked->state->label().').', 409);
            }

            $earliest = $locked->scheduled_at->copy()->subMinutes((int) config('longa.session_rules.start_early_minutes'));
            if (now()->lt($earliest)) {
                throw new ApiException('session_too_early', 'Só pode iniciar a aula a partir de '.$earliest->format('H:i').'.', 409);
            }

            if ($locked->ends_at !== null && now()->gt($locked->ends_at)) {
                throw new ApiException('session_too_late', 'A hora prevista da aula já terminou.', 409);
            }

            $this->lifecycle->transition($locked, SessionState::InProgress, 'started', $provider, [], ['started_at' => now()]);
            SessionStarted::dispatch($locked);

            return $locked;
        });
    }
}
