<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\DisputeResolved;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do admin sobre uma disputa: libertar ao técnico, reembolsar tudo ou reembolsar parte.
 *
 * Bloqueia a disputa e o serviço; a decisão financeira é uma transação do ledger com chave única por serviço.
 * Duas decisões simultâneas: a segunda encontra a disputa resolvida e não move dinheiro
 * (409 se a decisão for diferente, a mesma resposta se for igual).
 */
class ResolveDispute
{
    public const RELEASE = 'released';

    public const REFUND = 'refunded';

    public const PARTIAL = 'partially_refunded';

    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Dispute $dispute, User $admin, string $decision, ?int $providerShare, string $note): Dispute
    {
        return DB::transaction(function () use ($dispute, $admin, $decision, $providerShare, $note) {
            /** @var Dispute $locked */
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            /** @var ServiceRequest $session */
            $session = ServiceRequest::query()->lockForUpdate()->findOrFail($locked->service_request_id);

            if ($locked->status === Dispute::RESOLVED) {
                if ($locked->resolution === $decision) {
                    return $locked;
                }

                throw new ApiException('dispute_already_resolved', 'Esta disputa já foi resolvida por outro administrador.', 409);
            }

            // Em dinheiro o valor é o do serviço; no digital, o que ainda está retido (fases já libertadas não contam)
            $disputed = $session->isCash() ? $session->amount : $this->settlement->heldAmount($session);
            $gross = match ($decision) {
                self::RELEASE => $disputed,
                self::REFUND => 0,
                self::PARTIAL => $providerShare ?? throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['provider_share' => ['Indique a parte do técnico.']]),
                default => throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['decision' => ['Decisão inválida.']]),
            };

            if ($decision === self::PARTIAL && ($gross <= 0 || $gross >= $disputed)) {
                throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['provider_share' => ['A parte do técnico tem de estar entre 1 e '.($disputed - 1).' Kz.']]);
            }

            $metadata = ['dispute_id' => $locked->id, 'decision' => $decision, 'provider_share' => $gross, 'note' => $note];
            if ($session->state === RequestState::Disputed) {
                $refund = $decision === self::REFUND;
                $this->lifecycle->transition($session, $refund ? RequestState::Cancelled : RequestState::Paid, 'dispute_resolved', $admin, $metadata,
                    $refund ? ['closed_reason' => 'refunded', 'cancelled_at' => now()] : ['confirmed_at' => now()]);
            } else {
                $this->lifecycle->record($session, 'dispute_resolved', $admin, $metadata);
            }

            $this->settlement->split($session, $gross, $admin, "dispute:{$locked->id}:{$decision}");

            $locked->forceFill([
                'status' => Dispute::RESOLVED,
                'resolution' => $decision,
                'provider_share' => $gross,
                'resolution_note' => $note,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();

            DisputeResolved::dispatch($session, $locked);
            $this->audit->record('disputes.resolved', $locked, ['decision' => $decision, 'provider_share' => $gross, 'session_id' => $session->id], $admin->id);

            return $locked;
        });
    }
}
