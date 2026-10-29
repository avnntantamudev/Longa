<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Payments\Services\EscrowService;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\DisputeOpened;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\DisputeAttachment;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abrir disputa (o dinheiro fica retido até à decisão do admin):
 *  - cliente, com o serviço à espera de confirmação ("o serviço não aconteceu", "problema com o serviço");
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
    public function handle(ServiceRequest $session, User $user, string $reason, string $description, array $files = []): Dispute
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
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($session->id);

            $existing = $locked->disputes()->where('status', Dispute::OPEN)->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->ensureAllowed($locked, $user);

            $dispute = Dispute::query()->create([
                'service_request_id' => $locked->id,
                'opened_by' => $user->id,
                'reason' => $reason,
                'description' => $description,
            ]);

            foreach ($paths as $attachment) {
                DisputeAttachment::query()->create([...$attachment, 'dispute_id' => $dispute->id, 'uploaded_by' => $user->id]);
            }

            if ($locked->state === RequestState::Cancelled) {
                $this->lifecycle->record($locked, 'dispute_opened', $user, ['dispute_id' => $dispute->id, 'reason' => $reason, 'contest' => $locked->closed_reason]);
            } else {
                $this->lifecycle->transition($locked, RequestState::Disputed, 'dispute_opened', $user, ['dispute_id' => $dispute->id, 'reason' => $reason]);
            }
            $this->escrow->markDisputed($locked);

            DisputeOpened::dispatch($locked);
            $this->audit->record('disputes.opened', $dispute, ['session_id' => $locked->id, 'reason' => $reason], $user->id);

            return $dispute;
        });
    }

    private function ensureAllowed(ServiceRequest $session, User $user): void
    {
        $isClient = $session->client_id === $user->id;
        $isProvider = $session->provider->user_id === $user->id;

        $contestOpen = $session->no_show_contest_until?->isFuture() ?? false;
        $allowed = match ($session->state) {
            RequestState::InProgress, RequestState::Completed => $isClient || $isProvider,
            // Contestar a falta: a parte acusada, dentro do prazo
            RequestState::Cancelled => match ($session->closed_reason) {
                'no_show_provider' => $isProvider && $contestOpen,
                'no_show_client' => $isClient && $contestOpen,
                default => false,
            },
            default => false,
        };

        if (! $allowed) {
            throw new ApiException('dispute_not_allowed', 'Não é possível abrir uma disputa neste serviço neste momento.', 409);
        }
    }
}
