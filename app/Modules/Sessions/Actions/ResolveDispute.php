<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\DisputeResolved;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do admin sobre uma disputa: libertar ao formador, reembolsar tudo ou reembolsar parte.
 *
 * Bloqueia a disputa e a aula; a decisão financeira é uma transação do ledger com chave única por aula.
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
            /** @var ServiceJob $session */
            $session = ServiceJob::query()->lockForUpdate()->findOrFail($locked->service_job_id);

            if ($locked->status === Dispute::RESOLVED) {
                if ($locked->resolution === $decision) {
                    return $locked;
                }

                throw new ApiException('dispute_already_resolved', 'Esta disputa já foi resolvida por outro administrador.', 409);
            }

            $gross = match ($decision) {
                self::RELEASE => $session->amount,
                self::REFUND => 0,
                self::PARTIAL => $providerShare ?? throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['provider_share' => ['Indique a parte do formador.']]),
                default => throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['decision' => ['Decisão inválida.']]),
            };

            if ($decision === self::PARTIAL && ($gross <= 0 || $gross >= $session->amount)) {
                throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['provider_share' => ['A parte do formador tem de estar entre 1 e '.($session->amount - 1).' Kz.']]);
            }

            $target = match ($decision) {
                self::RELEASE => SessionState::Completed,
                self::REFUND => SessionState::Refunded,
                default => SessionState::PartiallyRefunded,
            };

            $this->lifecycle->transition($session, $target, 'dispute_resolved', $admin, [
                'dispute_id' => $locked->id, 'decision' => $decision, 'provider_share' => $gross, 'note' => $note,
            ], $target === SessionState::Completed ? ['confirmed_at' => now()] : []);

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
