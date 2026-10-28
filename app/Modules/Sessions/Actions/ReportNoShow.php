<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Reportar falta: o cliente reporta o técnico (≥ 15 min após o início) ou o técnico reporta o cliente
 * (≥ 30 min). O dinheiro fica retido durante o prazo de contestação (24 h); sem contestação, a regra R4
 * liquida conforme a política; com contestação, o serviço vai para disputa.
 */
class ReportNoShow
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    public function handle(ServiceJob $session, User $reporter, ?string $note): ServiceJob
    {
        return DB::transaction(function () use ($session, $reporter, $note) {
            /** @var ServiceJob $locked */
            $locked = ServiceJob::query()->lockForUpdate()->findOrFail($session->id);
            $byClient = $locked->client_id === $reporter->id;
            $target = $byClient ? SessionState::NoShowProvider : SessionState::NoShowClient;

            if ($locked->state === $target) {
                return $locked;
            }

            if ($locked->state !== SessionState::Scheduled) {
                throw new ApiException('no_show_not_allowed', 'Só pode reportar falta num serviço que ainda não começou.', 409);
            }

            $minutes = (int) config($byClient ? 'longa.session_rules.no_show_provider_after_minutes' : 'longa.session_rules.no_show_client_after_minutes');
            $from = $locked->scheduled_at->copy()->addMinutes($minutes);
            if (now()->lt($from)) {
                throw new ApiException('no_show_too_early', 'Só pode reportar a falta a partir das '.$from->format('H:i').'.', 409);
            }

            $contestUntil = now()->addHours((int) config('longa.session_rules.no_show_contest_hours'));
            $this->lifecycle->transition($locked, $target, 'no_show_reported', $reporter, [
                'note' => $note, 'contest_until' => $contestUntil->toIso8601String(),
            ], ['no_show_contest_until' => $contestUntil]);

            return $locked;
        });
    }
}
