<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\EscrowService;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\DisputeOpened;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\DisputeAttachment;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abrir disputa (o dinheiro fica retido até à decisão do admin):
 *  - aluno, com a aula à espera de confirmação ("a aula não aconteceu", "problema com a aula");
 *  - a parte acusada de faltar, dentro do prazo de contestação (24 h).
 */
class OpenDispute
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly EscrowService $escrow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(ServiceJob $session, User $user, string $reason, string $description, array $files = []): Dispute
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[] = [
                'path' => (string) $file->storeAs("disputes/{$session->id}", Str::uuid()->toString().'.'.$file->extension(), 'local'),
                'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ];
        }

        return DB::transaction(function () use ($session, $user, $reason, $description, $paths) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);

            $existing = $locked->disputes()->where('status', Dispute::OPEN)->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->ensureAllowed($locked, $user);

            $dispute = Dispute::query()->create([
                'service_job_id' => $locked->id,
                'opened_by' => $user->id,
                'reason' => $reason,
                'description' => $description,
            ]);

            foreach ($paths as $attachment) {
                DisputeAttachment::query()->create([...$attachment, 'dispute_id' => $dispute->id, 'uploaded_by' => $user->id]);
            }

            $this->lifecycle->transition($locked, SessionState::Disputed, 'dispute_opened', $user, ['dispute_id' => $dispute->id, 'reason' => $reason]);
            $this->escrow->markDisputed($locked);

            DisputeOpened::dispatch($locked);
            $this->audit->record('disputes.opened', $dispute, ['session_id' => $locked->id, 'reason' => $reason], $user->id);

            return $dispute;
        });
    }

    private function ensureAllowed(ServiceJob $session, User $user): void
    {
        $isClient = $session->client_id === $user->id;
        $isProvider = $session->provider->user_id === $user->id;

        $allowed = match ($session->state) {
            SessionState::AwaitingConfirmation => $isClient,
            // Contestar a falta: a parte acusada, dentro do prazo
            SessionState::NoShowProvider => $isProvider && $session->no_show_contest_until?->isFuture(),
            SessionState::NoShowClient => $isClient && $session->no_show_contest_until?->isFuture(),
            default => false,
        };

        if (! $allowed) {
            throw new ApiException('dispute_not_allowed', 'Não é possível abrir uma disputa nesta aula neste momento.', 409);
        }
    }
}
