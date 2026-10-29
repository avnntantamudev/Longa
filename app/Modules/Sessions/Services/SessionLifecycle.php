<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Models\SessionEvent;
use App\Modules\Users\Models\User;

/**
 * Transições da execução do pedido: a mudança de estado fica no histórico do pedido (com quem e o evento como
 * motivo) e o evento na linha do tempo (session_events, com os detalhes). Chamar sempre dentro da transação da
 * operação (ficam gravados juntos ou nenhum).
 */
class SessionLifecycle
{
    /**
     * @param  array<string, mixed>  $attributes  colunas a gravar com a transição (ex.: started_at)
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        ServiceRequest $request,
        RequestState $to,
        string $event,
        ?User $actor = null,
        array $metadata = [],
        array $attributes = [],
    ): ServiceRequest {
        $from = $request->state;
        $request->by($actor, $event)->transitionTo($to, $attributes);

        $this->record($request, $event, $actor, $metadata, $from, $to);

        return $request;
    }

    /**
     * Evento sem mudança de estado (ex.: QR emitido, sinal de risco).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ServiceRequest $request,
        string $event,
        ?User $actor = null,
        array $metadata = [],
        ?RequestState $from = null,
        ?RequestState $to = null,
    ): SessionEvent {
        return SessionEvent::query()->create([
            'service_request_id' => $request->id,
            'type' => $event,
            'from_state' => $from?->value,
            'to_state' => $to?->value,
            'actor_id' => $actor?->id,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
