<?php

declare(strict_types=1);

namespace App\Modules\Matching\Services;

use App\Modules\Matching\Jobs\AdvanceMatching;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferExpired;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Support\Settings\PlatformSettings;
use App\Support\StateMachine\InvalidTransition;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra o TRIM TRIM em ondas (docs/state-machines.md):
 *
 *  start()      draft → searching e envia a 1.ª onda;
 *  advance()    chamado pelo job atrasado de cada onda e pela varredura do scheduler: expira ofertas
 *               vencidas, envia a onda seguinte quando já não há ofertas abertas e expira o pedido
 *               quando acaba o tempo ou os candidatos.
 *
 * Todas as operações são idempotentes e seguras em concorrência (lock na linha do pedido +
 * transições condicionais), por isso o job e a varredura podem correr ao mesmo tempo.
 */
class MatchingCoordinator
{
    public function __construct(
        private readonly TrainerMatcher $matcher,
        private readonly ScoreRecorder $score,
        private readonly PlatformSettings $settings,
    ) {}

    public function start(TrainingRequest $request): int
    {
        $request->transitionTo(RequestState::Searching, [
            'expires_at' => $request->scheduled
                ? $request->scheduled_at->copy()->subHours($this->settings->int('scheduled_request_close_hours'))
                : now()->addSeconds((int) config('longa.requests.ttl')),
        ]);

        return $this->sendNextWave($request);
    }

    /**
     * Envia a próxima onda se o pedido estiver à procura e não houver ofertas em aberto.
     *
     * @return int ofertas enviadas
     */
    public function sendNextWave(TrainingRequest $request): int
    {
        if ($request->scheduled) {
            return $this->sendScheduledOffers($request);
        }

        $sent = DB::transaction(function () use ($request): array {
            /** @var TrainingRequest $locked */
            $locked = TrainingRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->state !== RequestState::Searching || $locked->expires_at?->isPast()) {
                return [];
            }

            if ($locked->offers()->whereIn('state', OfferState::open())->where('expires_at', '>', now())->exists()) {
                return [];
            }

            $size = (int) config($locked->wave === 0 ? 'longa.matching.first_wave_size' : 'longa.matching.next_wave_size');
            $already = $locked->offers()->pluck('trainer_profile_id')->all();
            $candidates = $this->matcher->candidates($locked, $size, $already);

            if ($candidates === []) {
                $this->expire($locked, $locked->wave === 0 ? 'no_trainers' : 'timeout');

                return [];
            }

            $wave = $locked->wave + 1;
            $now = now();
            // A oferta nunca dura mais do que o próprio pedido
            $offerExpiry = $now->copy()->addSeconds((int) config('longa.requests.offer_ttl'))->min($locked->expires_at);

            RequestOffer::query()->insert(array_map(fn ($c, int $i) => [
                'training_request_id' => $locked->id,
                'trainer_profile_id' => $c->trainerProfileId,
                'wave' => $wave,
                'rank' => count($already) + $i + 1,
                'distance_m' => $c->distanceM,
                'score_snapshot' => $c->score,
                'state' => OfferState::Sent->value,
                'sent_at' => $now,
                'expires_at' => $offerExpiry,
                'created_at' => $now,
                'updated_at' => $now,
            ], $candidates, array_keys($candidates)));

            $locked->forceFill(['wave' => $wave, 'next_wave_at' => $offerExpiry])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked->offers()->where('wave', $wave)->with(['trainer', 'request.skill'])->get()->all();
        });

        foreach ($sent as $offer) {
            OfferSent::dispatch($offer);
        }

        if ($sent !== []) {
            AdvanceMatching::dispatch($request->id)->delay($request->next_wave_at?->copy()->addSecond());
        }

        return count($sent);
    }

    /**
     * Pedido para outro dia: oferta a todos os elegíveis de uma vez (formadores a 5 km + VIP da mesma
     * província), válida até ao fecho do pedido. A cada varredura (10 min) entram formadores que
     * entretanto ficaram elegíveis. Sem candidatos não expira: espera até ao fecho.
     * Push só para os primeiros N (VIP primeiro); os restantes veem o pedido na lista "Pedidos agendados".
     *
     * @return int ofertas criadas
     */
    public function sendScheduledOffers(TrainingRequest $request): int
    {
        $maxPush = (int) config('longa.scheduled_requests.max_push');

        [$offers, $alreadyCount] = DB::transaction(function () use ($request): array {
            /** @var TrainingRequest $locked */
            $locked = TrainingRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->state !== RequestState::Searching || $locked->expires_at?->isPast()) {
                return [[], 0];
            }

            $already = $locked->offers()->pluck('trainer_profile_id')->all();
            $candidates = $this->matcher->candidates($locked, 500, $already, scheduled: true);
            $now = now();
            $nextSweep = $now->copy()->addMinutes(10)->min($locked->expires_at);

            if ($candidates !== []) {
                RequestOffer::query()->insert(array_map(fn ($c, int $i) => [
                    'training_request_id' => $locked->id,
                    'trainer_profile_id' => $c->trainerProfileId,
                    'wave' => $locked->wave + 1,
                    'rank' => count($already) + $i + 1,
                    'distance_m' => $c->distanceM,
                    'score_snapshot' => $c->score,
                    'state' => OfferState::Sent->value,
                    'sent_at' => $now,
                    'expires_at' => $locked->expires_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $candidates, array_keys($candidates)));
                $locked->wave++;
            }

            $locked->forceFill(['wave' => $locked->wave, 'next_wave_at' => $nextSweep])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            return [$candidates === [] ? [] : $locked->offers()->where('wave', $locked->wave)->orderBy('rank')->with(['trainer', 'request.skill'])->get()->all(), count($already)];
        });

        foreach (array_slice($offers, 0, max(0, $maxPush - $alreadyCount)) as $offer) {
            OfferSent::dispatch($offer);
        }

        return count($offers);
    }

    /**
     * Um passo do ciclo de vida do pedido (idempotente).
     */
    public function advance(TrainingRequest $request): void
    {
        $this->expireDueOffers($request->id);

        $request->refresh();

        if ($request->state !== RequestState::Searching) {
            return;
        }

        if ($request->expires_at?->isPast()) {
            DB::transaction(function () use ($request) {
                /** @var TrainingRequest $locked */
                $locked = TrainingRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ($locked->state === RequestState::Searching) {
                    $this->expire($locked, 'timeout');
                }
            });

            return;
        }

        $this->sendNextWave($request);
    }

    /**
     * Varredura global (scheduler): ofertas vencidas e pedidos que precisam de avançar.
     */
    public function sweep(): void
    {
        $this->expireDueOffers();

        TrainingRequest::query()
            ->where('state', RequestState::Searching->value)
            ->where(fn ($q) => $q->where('next_wave_at', '<=', now())->orWhere('expires_at', '<=', now()))
            ->limit(500)
            ->get()
            ->each(fn (TrainingRequest $r) => $this->advance($r));
    }

    /**
     * Ofertas abertas com o prazo vencido passam a "expired" (e contam para o score).
     */
    public function expireDueOffers(?int $requestId = null): int
    {
        $due = RequestOffer::query()
            ->whereIn('state', array_map(fn (OfferState $s) => $s->value, OfferState::open()))
            ->where('expires_at', '<=', now())
            ->when($requestId, fn ($q) => $q->where('training_request_id', $requestId))
            ->with(['trainer', 'request'])
            ->limit(1000)
            ->get();

        $expired = 0;
        foreach ($due as $offer) {
            try {
                $offer->transitionTo(OfferState::Expired);
            } catch (InvalidTransition) {
                // Respondida entretanto (aceite, recusada ou perdida): nada a fazer
                continue;
            }

            if (! $offer->request->scheduled) {
                $this->score->record($offer->trainer_profile_id, ScoreEvent::OFFER_EXPIRED, $offer);
            }
            OfferExpired::dispatch($offer);
            $expired++;
        }

        return $expired;
    }

    /**
     * searching → expired; ofertas ainda abertas → lost. Chamar com a linha do pedido bloqueada.
     *
     * @param  'no_trainers'|'timeout'  $reason
     */
    private function expire(TrainingRequest $request, string $reason): void
    {
        $request->transitionTo(RequestState::Expired, ['next_wave_at' => null]);

        $open = $request->offers()->whereIn('state', OfferState::open())->with('trainer')->get();
        foreach ($open as $offer) {
            $offer->transitionTo(OfferState::Lost, ['responded_at' => now()]);
            OfferExpired::dispatch($offer);
        }

        RequestExpired::dispatch($request, $reason);
    }
}
