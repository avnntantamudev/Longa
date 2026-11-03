<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Notifications\Notifications\PackageUpdateNotification;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remarcar uma aula de um pacote: uma das partes propõe outra hora e a outra aceita ou recusa. No máximo N
 * remarcações por pacote, com X horas de antecedência (antes da hora atual e da nova), dentro do prazo do pacote
 * e com o técnico livre à nova hora.
 */
class LessonReschedules
{
    public function __construct(
        private readonly ProviderMatcher $matcher,
        private readonly SessionLifecycle $lifecycle,
        private readonly PlatformSettings $settings,
    ) {}

    public function canPropose(ServiceRequest $lesson, bool $isProvider): bool
    {
        return $lesson->isPackageLesson()
            && $lesson->state === RequestState::Accepted
            && $lesson->reschedule_to === null
            && now()->lte($lesson->scheduled_at->copy()->subHours($this->noticeHours()))
            && $this->remaining($lesson) > 0;
    }

    public function canAnswer(ServiceRequest $lesson, bool $isProvider): bool
    {
        if (! $lesson->isPackageLesson() || $lesson->reschedule_to === null || $lesson->state !== RequestState::Accepted) {
            return false;
        }
        $proposedByProvider = $lesson->reschedule_by === $lesson->provider->user_id;

        return $isProvider ? ! $proposedByProvider : $proposedByProvider;
    }

    /** Remarcações que o pacote ainda pode usar. */
    public function remaining(ServiceRequest $lesson): int
    {
        $used = (int) ServiceRequest::query()->whereKey($lesson->package_id)->value('reschedules_used');

        return max(0, (int) config('longa.packages.reschedule_max') - $used);
    }

    public function propose(ServiceRequest $lesson, User $actor, Carbon $to): ServiceRequest
    {
        return DB::transaction(function () use ($lesson, $actor, $to) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($lesson->id);
            $isProvider = $locked->provider->user_id === $actor->id;

            if (! $this->canPropose($locked, $isProvider)) {
                throw new ApiException('reschedule_not_allowed', $this->remaining($locked) === 0
                    ? 'Este pacote já usou todas as remarcações.'
                    : 'Esta aula já não pode ser remarcada (faltam menos de '.$this->noticeHours().' horas ou já há uma proposta).', 409);
            }
            $this->ensureValidTime($locked, $to);

            $locked->forceFill(['reschedule_to' => $to, 'reschedule_by' => $actor->id])->save();
            $this->lifecycle->record($locked, 'reschedule_proposed', $actor, ['to' => $to->toIso8601String()]);
            $this->notifyOther($locked, $actor, 'reschedule_proposed', 'Pedido para remarcar uma aula',
                "{$actor->name} propõe mudar \"{$locked->title}\" para ".$to->copy()->setTimezone('Africa/Luanda')->format('d/m \à\s H:i').'. Aceite ou recuse na app.');

            return $locked;
        });
    }

    public function accept(ServiceRequest $lesson, User $actor): ServiceRequest
    {
        return DB::transaction(function () use ($lesson, $actor) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($lesson->id);
            $this->ensureCanAnswer($locked, $actor);
            $to = $locked->reschedule_to ?? throw new ApiException('no_reschedule', 'Não há nenhuma proposta para esta aula.', 409);
            $this->ensureValidTime($locked, $to);

            $from = $locked->scheduled_at->toIso8601String();
            $locked->forceFill(['scheduled_at' => $to, 'reschedule_to' => null, 'reschedule_by' => null])->save();
            ServiceRequest::query()->whereKey($locked->package_id)->increment('reschedules_used');
            $this->lifecycle->record($locked, 'rescheduled', $actor, ['from' => $from, 'to' => $to->toIso8601String()]);
            $this->notifyOther($locked, $actor, 'rescheduled', 'Aula remarcada',
                "\"{$locked->title}\" passa para ".$to->copy()->setTimezone('Africa/Luanda')->format('d/m \à\s H:i').'.');

            return $locked;
        });
    }

    public function reject(ServiceRequest $lesson, User $actor): ServiceRequest
    {
        return DB::transaction(function () use ($lesson, $actor) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($lesson->id);
            $this->ensureCanAnswer($locked, $actor);

            $locked->forceFill(['reschedule_to' => null, 'reschedule_by' => null])->save();
            $this->lifecycle->record($locked, 'reschedule_rejected', $actor);
            $this->notifyOther($locked, $actor, 'reschedule_rejected', 'Remarcação recusada', "A aula \"{$locked->title}\" mantém a hora marcada.");

            return $locked;
        });
    }

    private function ensureCanAnswer(ServiceRequest $lesson, User $actor): void
    {
        $isProvider = $lesson->provider->user_id === $actor->id;
        if (! $this->canAnswer($lesson, $isProvider)) {
            throw new ApiException('reschedule_not_yours', 'Não há nenhuma proposta da outra parte para responder.', 409);
        }
    }

    /**
     * A nova hora: com antecedência, dentro do prazo do pacote e com o técnico livre (sem contar esta aula).
     */
    private function ensureValidTime(ServiceRequest $lesson, Carbon $to): void
    {
        $notice = $this->noticeHours();
        if ($to->lt(now()->addHours($notice))) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['scheduled_at' => ["A nova hora tem de ser daqui a pelo menos {$notice} horas."]]);
        }

        /** @var ServiceRequest $package */
        $package = ServiceRequest::query()->findOrFail($lesson->package_id);
        $first = Carbon::parse(($package->lesson_schedule ?? [])[0] ?? $package->scheduled_at);
        $weeks = $this->settings->int('packages_max_weeks');
        if ($to->gt($first->copy()->addWeeks($weeks))) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['scheduled_at' => ["A aula tem de ficar dentro das {$weeks} semanas do pacote."]]);
        }

        $moved = $lesson->replicate();
        $moved->id = $lesson->id;
        $moved->scheduled_at = $to;
        if ($this->matcher->hasConflict((int) $lesson->provider_profile_id, $moved)) {
            throw new ApiException('schedule_conflict', 'O técnico já tem outro serviço ou aula a essa hora.', 409);
        }
    }

    private function notifyOther(ServiceRequest $lesson, User $actor, string $kind, string $title, string $body): void
    {
        $other = $lesson->client_id === $actor->id ? $lesson->provider->user : $lesson->client;
        $other->notify(new PackageUpdateNotification($kind, $title, $body, "/servicos/{$lesson->id}"));
    }

    private function noticeHours(): int
    {
        return (int) config('longa.packages.reschedule_notice_hours');
    }
}
