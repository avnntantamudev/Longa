<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Notifications\Notifications\ProviderSanctionNotification;
use App\Modules\Ratings\Models\ProviderSanction;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Avaliações nos dois sentidos (BISCA, Fase 3.3). Abertas só depois de o pedido estar pago e durante N dias.
 * A média das últimas N avaliações do técnico é a reputação dele (usada no despacho) e alimenta as regras graduais:
 *
 *  - aviso abaixo de 3,5 (pelo menos 5 avaliações; no máximo um por mês);
 *  - suspensão de 7 dias abaixo de 3,0 (pelo menos 10); o técnico deixa de receber pedidos;
 *  - remoção abaixo de 2,5 (pelo menos 20) ou à 3.ª suspensão: fica pendente até a equipa confirmar ou cancelar.
 *
 * Limites em Definições → Avaliações (médias em décimas: 35 = 3,5).
 */
class RatingService
{
    public function __construct(private readonly PlatformSettings $settings, private readonly AuditLogger $audit) {}

    public function rate(ServiceRequest $request, User $rater, int $stars, ?string $comment): Rating
    {
        $rating = DB::transaction(function () use ($request, $rater, $stars, $comment) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $existing = Rating::query()->where('service_request_id', $locked->id)->where('rater_id', $rater->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $this->ensureOpen($locked);

            $byClient = $locked->client_id === $rater->id;
            $rating = Rating::query()->create([
                'service_request_id' => $locked->id,
                'rater_id' => $rater->id,
                'ratee_id' => $byClient ? $locked->provider->user_id : $locked->client_id,
                'direction' => $byClient ? Rating::CLIENT_TO_PROVIDER : Rating::PROVIDER_TO_CLIENT,
                'stars' => $stars,
                'comment' => $comment,
            ]);

            if ($byClient) {
                $this->refreshReputation($locked->provider);
            }

            return $rating;
        });

        if ($rating->direction === Rating::CLIENT_TO_PROVIDER && $rating->wasRecentlyCreated) {
            $this->applyRules($request->provider->fresh() ?? $request->provider);
        }

        return $rating;
    }

    public function canRate(ServiceRequest $request, User $user): bool
    {
        return $request->state === RequestState::Paid
            && $request->confirmed_at !== null
            && $request->confirmed_at->copy()->addDays($this->settings->int('rating_window_days'))->isFuture()
            && $request->involves($user)
            && ! Rating::query()->where('service_request_id', $request->id)->where('rater_id', $user->id)->exists();
    }

    /**
     * Média e número das últimas N avaliações recebidas pelo técnico.
     *
     * @return array{average: float, count: int}
     */
    public function recentAverage(ProviderProfile $provider): array
    {
        $stars = Rating::query()->where('ratee_id', $provider->user_id)->where('direction', Rating::CLIENT_TO_PROVIDER)
            ->orderByDesc('id')->limit((int) config('longa.ratings.sample_size'))->pluck('stars');

        return ['average' => $stars->isEmpty() ? 0.0 : round((float) $stars->avg(), 2), 'count' => $stars->count()];
    }

    /**
     * Média das avaliações recebidas por um cliente (o técnico vê-a antes do serviço).
     *
     * @return array{average: float, count: int}
     */
    public function clientAverage(User $client): array
    {
        $stars = Rating::query()->where('ratee_id', $client->id)->where('direction', Rating::PROVIDER_TO_CLIENT)->pluck('stars');

        return ['average' => $stars->isEmpty() ? 0.0 : round((float) $stars->avg(), 2), 'count' => $stars->count()];
    }

    public function applyRules(ProviderProfile $provider): ?ProviderSanction
    {
        ['average' => $average, 'count' => $count] = $this->recentAverage($provider);
        $below = fn (string $key) => $average * 10 < $this->settings->int($key);
        $min = config('longa.ratings.min_ratings');

        $suspensions = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::SUSPENSION)->count();
        $pendingRemoval = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::REMOVAL)
            ->whereIn('status', [ProviderSanction::PENDING_REVIEW, ProviderSanction::CONFIRMED])->exists();

        if (! $pendingRemoval && (($count >= $min['removal'] && $below('rating_remove_below_tenths')) || $suspensions >= (int) config('longa.ratings.max_suspensions'))) {
            return $this->sanction($provider, ProviderSanction::REMOVAL, ProviderSanction::PENDING_REVIEW, $average, $count,
                $suspensions >= (int) config('longa.ratings.max_suspensions') ? "{$suspensions} suspensões" : 'Média abaixo do limite de remoção');
        }

        $activeSuspension = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::SUSPENSION)->where('status', ProviderSanction::ACTIVE)->exists();
        if (! $activeSuspension && $count >= $min['suspension'] && $below('rating_suspend_below_tenths')) {
            $sanction = $this->sanction($provider, ProviderSanction::SUSPENSION, ProviderSanction::ACTIVE, $average, $count, 'Média abaixo do limite de suspensão',
                now()->addDays($this->settings->int('rating_suspension_days')));
            $provider->forceFill(['verification_status' => VerificationStatus::Suspended, 'is_available' => false])->save();

            return $sanction;
        }

        $recentWarning = ProviderSanction::query()->where('provider_profile_id', $provider->id)->where('level', ProviderSanction::WARNING)->where('created_at', '>', now()->subDays(30))->exists();
        if (! $recentWarning && $count >= $min['warning'] && $below('rating_warn_below_tenths')) {
            return $this->sanction($provider, ProviderSanction::WARNING, ProviderSanction::ENDED, $average, $count, 'Média abaixo do limite de aviso');
        }

        return null;
    }

    /**
     * Suspensões que terminaram: o técnico volta a estar verificado (scheduler).
     */
    public function liftExpiredSuspensions(): int
    {
        $count = 0;
        $due = ProviderSanction::query()->where('level', ProviderSanction::SUSPENSION)->where('status', ProviderSanction::ACTIVE)->where('ends_at', '<=', now())->with('provider')->get();
        foreach ($due as $sanction) {
            DB::transaction(function () use ($sanction) {
                $sanction->forceFill(['status' => ProviderSanction::ENDED])->save();
                if ($sanction->provider->verification_status === VerificationStatus::Suspended) {
                    $sanction->provider->forceFill(['verification_status' => VerificationStatus::Verified])->save();
                }
            });
            $this->audit->record('ratings.suspension_lifted', $sanction, ['provider_profile_id' => $sanction->provider_profile_id]);
            $count++;
        }

        return $count;
    }

    /**
     * Revisão da equipa de uma remoção pendente: confirmar (técnico removido) ou cancelar.
     */
    public function reviewRemoval(ProviderSanction $sanction, User $reviewer, bool $confirm, string $note): ProviderSanction
    {
        $result = DB::transaction(function () use ($sanction, $reviewer, $confirm, $note) {
            /** @var ProviderSanction $locked */
            $locked = ProviderSanction::query()->lockForUpdate()->findOrFail($sanction->id);
            if ($locked->level !== ProviderSanction::REMOVAL || $locked->status !== ProviderSanction::PENDING_REVIEW) {
                throw new ApiException('sanction_decided', 'Esta remoção já foi decidida.', 409);
            }
            $locked->forceFill([
                'status' => $confirm ? ProviderSanction::CONFIRMED : ProviderSanction::CANCELLED,
                'decided_by' => $reviewer->id, 'decided_at' => now(), 'decision_note' => $note,
            ])->save();
            if ($confirm) {
                $locked->provider->forceFill(['verification_status' => VerificationStatus::Removed, 'is_available' => false])->save();
            }

            return $locked;
        });

        $this->audit->record($confirm ? 'ratings.removal_confirmed' : 'ratings.removal_cancelled', $result, ['note' => $note], $reviewer->id);
        if ($confirm) {
            $result->provider->user->notify(new ProviderSanctionNotification($result));
        }

        return $result;
    }

    private function refreshReputation(ProviderProfile $provider): void
    {
        // Reputação = média das últimas N; o número é o total de avaliações recebidas
        $total = Rating::query()->where('ratee_id', $provider->user_id)->where('direction', Rating::CLIENT_TO_PROVIDER)->count();
        $provider->forceFill(['rating_average' => $this->recentAverage($provider)['average'], 'rating_count' => $total])->save();
    }

    private function sanction(ProviderProfile $provider, string $level, string $status, float $average, int $count, string $reason, ?\Illuminate\Support\Carbon $endsAt = null): ProviderSanction
    {
        $sanction = ProviderSanction::query()->create([
            'provider_profile_id' => $provider->id, 'level' => $level, 'status' => $status,
            'average' => $average, 'ratings_count' => $count, 'reason' => $reason, 'ends_at' => $endsAt,
        ]);
        $this->audit->record("ratings.{$level}", $sanction, ['average' => $average, 'count' => $count, 'provider_profile_id' => $provider->id]);
        if ($level !== ProviderSanction::REMOVAL) {
            $provider->user->notify(new ProviderSanctionNotification($sanction));
        }

        return $sanction;
    }

    private function ensureOpen(ServiceRequest $request): void
    {
        if ($request->state !== RequestState::Paid || $request->confirmed_at === null) {
            throw new ApiException('rating_not_open', 'Só pode avaliar depois de o serviço estar pago.', 409);
        }
        if ($request->confirmed_at->copy()->addDays($this->settings->int('rating_window_days'))->isPast()) {
            throw new ApiException('rating_closed', 'O prazo para avaliar este serviço terminou.', 410);
        }
    }
}
