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
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Support\Settings\PlatformSettings;
use App\Support\StateMachine\InvalidTransition;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra o despacho do pedido (BISCA, Fase 2.3; docs/state-machines.md):
 *
 *  start()      created → searching e envia a 1.ª ronda;
 *  sendNextWave() presencial no próprio dia: rondas de raio crescente (raio inicial, + alargamento por ronda, até
 *               ao raio máximo), repartidas pelo tempo total de procura. Cada ronda oferece a técnicos novos dentro
 *               do raio; as ofertas enviadas ficam abertas até alguém aceitar ou a procura acabar. Depois do raio
 *               máximo continua a oferecer, à mesma distância, a quem ficar disponível;
 *               online ou para outro dia: oferta a todos os elegíveis de uma vez (sem perímetro no online);
 *  advance()    chamado pelo job atrasado de cada ronda e pela varredura do scheduler: abre a ronda seguinte e
 *               passa o pedido a "sem técnico disponível" quando acaba o tempo;
 *  retry()      nova tentativa de um pedido "sem técnico disponível" (volta a oferecer a quem não respondeu);
 *  startDirect() pedido direto ao mesmo técnico (VIP): uma só oferta, durante X minutos, sem rondas. Se o técnico
 *               recusar ou não responder, o pedido fica "sem técnico" e a nova tentativa envia-o a todos.
 *
 * Todas as operações são idempotentes e seguras em concorrência (lock na linha do pedido +
 * transições condicionais), por isso o job e a varredura podem correr ao mesmo tempo.
 * Parâmetros em Definições → Despacho (PlatformSettings dispatch_*).
 */
class MatchingCoordinator
{
    public function __construct(
        private readonly ProviderMatcher $matcher,
        private readonly ScoreRecorder $score,
        private readonly PlatformSettings $settings,
    ) {}

    public function start(ServiceRequest $request): int
    {
        if ($request->isDirect()) {
            return $this->startDirect($request);
        }

        $expiresAt = $request->scheduled || $request->isPackage()
            ? $request->scheduled_at->copy()->subHours($this->settings->int('scheduled_request_close_hours'))
            : now()->addMinutes($this->settings->int('dispatch_search_minutes'));
        $request->transitionTo(RequestState::Searching, [
            'expires_at' => $expiresAt,
            'wave' => 0,
            'next_wave_at' => null,
            // Pacote: primeiro só técnicos VIP (durante a janela; com "só VIP", até ao fecho)
            'vip_only_until' => ! $request->isPackage() ? null
                : ($this->settings->int('packages_vip_only') === 1 ? $expiresAt : now()->addMinutes($this->settings->int('packages_vip_window_minutes'))->min($expiresAt)),
        ]);

        return $this->sendNextWave($request);
    }

    /**
     * Abre a próxima ronda do despacho (se a ronda atual já terminou).
     *
     * @return int ofertas enviadas
     */
    public function sendNextWave(ServiceRequest $request): int
    {
        // Pedido direto: só o técnico escolhido, sem rondas
        if ($request->isDirect()) {
            return 0;
        }

        if ($request->scheduled || $request->modality === 'online' || $request->isPackage()) {
            return $this->sendScheduledOffers($request);
        }

        $opened = false;
        $sent = DB::transaction(function () use ($request, &$opened): array {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->state !== RequestState::Searching || $locked->expires_at?->isPast()) {
                return [];
            }

            // A ronda atual ainda está a decorrer (o job e a varredura podem chegar os dois)
            if ($locked->next_wave_at !== null && $locked->next_wave_at->isFuture()) {
                return [];
            }

            $wave = $locked->wave + 1;
            $radius = $this->radiusFor($wave);
            $already = $locked->offers()->pluck('provider_profile_id')->all();
            $candidates = $this->matcher->candidates($locked, (int) config('longa.matching.round_size'), $already, radiusM: $radius);

            // Ninguém pode servir este pedido agora, nem no raio máximo: não faz esperar o cliente
            if ($candidates === [] && $already === []
                && $this->matcher->candidates($locked, 1, radiusM: $this->settings->int('dispatch_max_radius_m')) === []) {
                $this->expire($locked, 'no_providers');

                return [];
            }

            $now = now();
            if ($candidates !== []) {
                RequestOffer::query()->insert(array_map(fn ($c, int $i) => [
                    'service_request_id' => $locked->id,
                    'provider_profile_id' => $c->providerProfileId,
                    'wave' => $wave,
                    'rank' => count($already) + $i + 1,
                    'distance_m' => $c->distanceM,
                    'score_snapshot' => $c->score,
                    'state' => OfferState::Sent->value,
                    'sent_at' => $now,
                    // A oferta fica aberta até ao fim da procura (ou até alguém aceitar)
                    'expires_at' => $locked->expires_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $candidates, array_keys($candidates)));
            }

            $nextRound = $now->copy()->addSeconds($this->roundSeconds())->min($locked->expires_at);
            $locked->forceFill(['wave' => min($wave, 255), 'next_wave_at' => $nextRound])->save();
            $request->setRawAttributes($locked->getAttributes(), true);
            $opened = true;

            return $candidates === [] ? [] : $locked->offers()->where('wave', $wave)->with(['provider', 'request.category'])->get()->all();
        });

        foreach ($sent as $offer) {
            OfferSent::dispatch($offer);
        }

        // Só quando abriu uma ronda (com a fila síncrona, o job corre logo e não pode voltar a agendar-se)
        if ($opened && $request->next_wave_at !== null) {
            AdvanceMatching::dispatch($request->id)->delay($request->next_wave_at->copy()->addSecond());
        }

        return count($sent);
    }

    /**
     * Raio da ronda N (metros): inicial + (N − 1) × alargamento, sem passar do máximo.
     */
    public function radiusFor(int $wave): int
    {
        $initial = $this->settings->int('dispatch_initial_radius_m');
        $max = max($initial, $this->settings->int('dispatch_max_radius_m'));

        return min($max, $initial + (max(1, $wave) - 1) * $this->settings->int('dispatch_radius_step_m'));
    }

    /**
     * Rondas até ao raio máximo (ex.: 3 → 15 km de 3 em 3 km = 5 rondas).
     */
    public function rounds(): int
    {
        $initial = $this->settings->int('dispatch_initial_radius_m');
        $max = $this->settings->int('dispatch_max_radius_m');

        return $max <= $initial ? 1 : intdiv($max - $initial + $this->settings->int('dispatch_radius_step_m') - 1, $this->settings->int('dispatch_radius_step_m')) + 1;
    }

    /**
     * Duração de cada ronda: o tempo total de procura repartido pelas rondas (ex.: 60 min / 5 = 12 min).
     */
    public function roundSeconds(): int
    {
        return max(30, intdiv($this->settings->int('dispatch_search_minutes') * 60, $this->rounds()));
    }

    /**
     * Nova tentativa (sem técnico disponível → à procura): nova janela de procura, volta a oferecer a quem não
     * respondeu (as ofertas recusadas ficam recusadas) e abre as rondas outra vez.
     */
    public function retry(ServiceRequest $request): int
    {
        $reopened = DB::transaction(function () use ($request): array {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $locked->transitionTo(RequestState::Searching, [
                'expires_at' => now()->addMinutes($this->settings->int('dispatch_search_minutes')),
                'wave' => 0,
                'next_wave_at' => null,
                // Pedido direto sem resposta: a nova tentativa envia a todos
                'direct_provider_id' => null,
            ]);
            $request->setRawAttributes($locked->getAttributes(), true);

            $offers = $locked->offers()->whereIn('state', [OfferState::Expired->value, OfferState::Lost->value])->with(['provider', 'request.category'])->get();
            foreach ($offers as $offer) {
                $offer->transitionTo(OfferState::Sent, ['sent_at' => now(), 'expires_at' => $locked->expires_at, 'responded_at' => null, 'viewed_at' => null]);
            }

            return $offers->all();
        });

        foreach ($reopened as $offer) {
            OfferSent::dispatch($offer);
        }

        return count($reopened) + $this->sendNextWave($request);
    }

    /**
     * Pedido direto: uma só oferta ao técnico escolhido, válida X minutos (Definições). Se ele já não puder
     * (ficou ocupado, sem saldo, deixou de ser VIP), o pedido fica logo "sem técnico" para o cliente enviar a todos.
     *
     * @return int ofertas enviadas (0 ou 1)
     */
    public function startDirect(ServiceRequest $request): int
    {
        $offer = DB::transaction(function () use ($request): ?RequestOffer {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $expiresAt = now()->addMinutes($this->settings->int('direct_request_minutes'))->min($locked->scheduled_at);
            $locked->transitionTo(RequestState::Searching, ['expires_at' => $expiresAt, 'wave' => 1, 'next_wave_at' => $expiresAt]);
            $request->setRawAttributes($locked->getAttributes(), true);

            $candidate = $this->matcher->directCandidate($locked, (int) $locked->direct_provider_id);
            if ($candidate === null) {
                $this->expire($locked, 'direct_unavailable');
                $request->setRawAttributes($locked->getAttributes(), true);

                return null;
            }

            return RequestOffer::query()->create([
                'service_request_id' => $locked->id,
                'provider_profile_id' => $candidate->providerProfileId,
                'wave' => 1,
                'rank' => 1,
                'distance_m' => $candidate->distanceM,
                'score_snapshot' => $candidate->score,
                'state' => OfferState::Sent->value,
                'sent_at' => now(),
                'expires_at' => $expiresAt,
            ])->load(['provider', 'request.category']);
        });

        if ($offer === null) {
            return 0;
        }

        OfferSent::dispatch($offer);
        AdvanceMatching::dispatch($request->id)->delay($offer->expires_at->copy()->addSecond());

        return 1;
    }

    /**
     * O técnico de um pedido direto recusou: não faz o cliente esperar pelo fim do prazo.
     */
    public function closeDeclinedDirect(ServiceRequest $request): void
    {
        DB::transaction(function () use ($request) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->isDirect() && $locked->state === RequestState::Searching
                && ! $locked->offers()->whereIn('state', OfferState::open())->exists()) {
                $this->expire($locked, 'direct_declined');
            }
        });
    }

    /**
     * Pedido para outro dia: oferta a todos os elegíveis de uma vez (técnicos a 5 km + VIP da mesma
     * província), válida até ao fecho do pedido. A cada varredura (10 min) entram técnicos que
     * entretanto ficaram elegíveis. Sem candidatos não expira: espera até ao fecho.
     * Push só para os primeiros N (VIP primeiro); os restantes veem o pedido na lista "Pedidos agendados".
     *
     * @return int ofertas criadas
     */
    public function sendScheduledOffers(ServiceRequest $request): int
    {
        $maxPush = (int) config('longa.scheduled_requests.max_push');

        [$offers, $alreadyCount] = DB::transaction(function () use ($request): array {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->state !== RequestState::Searching || $locked->expires_at?->isPast()) {
                return [[], 0];
            }

            $already = $locked->offers()->pluck('provider_profile_id')->all();
            $vipUntil = $locked->vip_only_until;
            $vipOnly = $vipUntil !== null && $vipUntil->isFuture();
            $candidates = $this->matcher->candidates($locked, 500, $already, scheduled: true, vipOnly: $vipOnly);
            $now = now();
            $nextSweep = $now->copy()->addMinutes(10)->min($locked->expires_at);
            // Fim da janela VIP: a varredura seguinte abre o pacote a todos
            if ($vipUntil !== null && $vipOnly) {
                $nextSweep = $nextSweep->min($vipUntil->copy()->addSecond());
            }

            if ($candidates !== []) {
                RequestOffer::query()->insert(array_map(fn ($c, int $i) => [
                    'service_request_id' => $locked->id,
                    'provider_profile_id' => $c->providerProfileId,
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

            return [$candidates === [] ? [] : $locked->offers()->where('wave', $locked->wave)->orderBy('rank')->with(['provider', 'request.category'])->get()->all(), count($already)];
        });

        foreach (array_slice($offers, 0, max(0, $maxPush - $alreadyCount)) as $offer) {
            OfferSent::dispatch($offer);
        }

        return count($offers);
    }

    /**
     * Um passo do ciclo de vida do pedido (idempotente).
     */
    public function advance(ServiceRequest $request): void
    {
        $this->expireDueOffers($request->id);

        $request->refresh();

        if ($request->state !== RequestState::Searching) {
            return;
        }

        if ($request->expires_at?->isPast()) {
            DB::transaction(function () use ($request) {
                /** @var ServiceRequest $locked */
                $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ($locked->state === RequestState::Searching) {
                    $this->expire($locked, match (true) {
                        $locked->isDirect() => 'direct_timeout',
                        $locked->offers()->exists() => 'timeout',
                        default => 'no_providers',
                    });
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

        ServiceRequest::query()
            ->where('state', RequestState::Searching->value)
            ->where(fn ($q) => $q->where('next_wave_at', '<=', now())->orWhere('expires_at', '<=', now()))
            ->limit(500)
            ->get()
            ->each(fn (ServiceRequest $r) => $this->advance($r));
    }

    /**
     * Ofertas abertas com o prazo vencido passam a "expired" (e contam para o score).
     */
    public function expireDueOffers(?int $requestId = null): int
    {
        $due = RequestOffer::query()
            ->whereIn('state', array_map(fn (OfferState $s) => $s->value, OfferState::open()))
            ->where('expires_at', '<=', now())
            ->when($requestId, fn ($q) => $q->where('service_request_id', $requestId))
            ->with(['provider', 'request'])
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
                $this->score->record($offer->provider_profile_id, ScoreEvent::OFFER_EXPIRED, $offer);
            }
            OfferExpired::dispatch($offer);
            $expired++;
        }

        return $expired;
    }

    /**
     * searching → no_provider; ofertas ainda abertas → lost. Chamar com a linha do pedido bloqueada.
     *
     * @param  'no_providers'|'timeout'|'direct_timeout'|'direct_declined'|'direct_unavailable'  $reason
     */
    private function expire(ServiceRequest $request, string $reason): void
    {
        $request->by(null, $reason)->transitionTo(RequestState::NoProvider, ['next_wave_at' => null]);

        $open = $request->offers()->whereIn('state', OfferState::open())->with('provider')->get();
        foreach ($open as $offer) {
            $offer->transitionTo(OfferState::Lost, ['responded_at' => now()]);
            OfferExpired::dispatch($offer);
        }

        RequestExpired::dispatch($request, $reason);
    }
}
