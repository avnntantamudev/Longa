<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Users\Models\User;

/**
 * Transições da aula com registo obrigatório na linha do tempo (session_events).
 * Chamar sempre dentro da transação de base de dados da operação (o evento e a transição
 * ficam gravados juntos ou nenhum).
 */
class SessionLifecycle
{
    /**
     * @param  array<string, mixed>  $attributes  colunas a gravar com a transição (ex.: started_at)
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        ServiceJob $session,
        SessionState $to,
        string $event,
        ?User $actor = null,
        array $metadata = [],
        array $attributes = [],
    ): ServiceJob {
        $from = $session->state;
        $session->transitionTo($to, $attributes);

        $this->record($session, $event, $actor, $metadata, $from, $to);

        return $session;
    }

    /**
     * Evento sem mudança de estado (ex.: QR emitido, sinal de risco).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ServiceJob $session,
        string $event,
        ?User $actor = null,
        array $metadata = [],
        ?SessionState $from = null,
        ?SessionState $to = null,
    ): SessionEvent {
        return SessionEvent::query()->create([
            'service_job_id' => $session->id,
            'type' => $event,
            'from_state' => $from?->value,
            'to_state' => $to?->value,
            'actor_id' => $actor?->id,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
