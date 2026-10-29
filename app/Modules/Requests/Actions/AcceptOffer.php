<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Enums\PricingMode;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aceitação atómica de uma oferta TRIM TRIM. Tudo ou nada, numa única transação:
 *
 *  1. lock em Redis por pedido (reduz a disputa na BD quando muitos técnicos carregam ao mesmo tempo);
 *  2. SELECT … FOR UPDATE na linha do pedido (serializa as aceitações do mesmo pedido entre processos);
 *  3. verifica o estado do pedido e da oferta e o prazo (decidido pelo servidor);
 *  4. aceita a oferta, marca as outras como "lost", pedido → "accepted" com o técnico e a comissão dele, cria a conversa;
 *  5. pagamento digital fica à espera (payment_status = pending, o cliente paga na janela de pagamento e o valor vai
 *     para o escrow); em dinheiro não há pagamento antecipado.
 *
 * Idempotente: se o mesmo técnico repetir o pedido depois de ganhar, recebe o mesmo pedido.
 * Última barreira na BD: índice único de oferta aceite por pedido.
 */
class AcceptOffer
{
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly SessionLifecycle $lifecycle, private readonly AuditLogger $audit) {}

    public function handle(RequestOffer $offer): ServiceRequest
    {
        try {
            return Cache::lock('accept:request:'.$offer->service_request_id, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->accept($offer));
        } catch (LockTimeoutException) {
            throw new ApiException('try_again', 'Muitos pedidos ao mesmo tempo. Tente novamente.', 503);
        }
    }

    private function accept(RequestOffer $offer): ServiceRequest
    {
        $result = DB::transaction(function () use ($offer): array {
            /** @var ServiceRequest $request */
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($offer->service_request_id);
            /** @var RequestOffer $offer */
            $offer = RequestOffer::query()->lockForUpdate()->findOrFail($offer->id);

            // Idempotência: este técnico já ganhou este pedido
            if ($offer->state === OfferState::Accepted) {
                return ['request' => $request, 'new' => false];
            }

            $this->ensureCanAccept($request, $offer);

            // Hora/pacote: o total fixa-se com o preço por hora do técnico que aceita (nunca acima do máximo do cliente)
            $hourly = $request->pricing_mode === PricingMode::HourlyPackage ? $this->hourlyRateOf($request, $offer) : null;
            $amount = $hourly === null ? $request->amount : HourlyPricing::total($hourly, $request->duration_minutes, $request->sessions, $request->discount_percent);

            // Comissão do técnico que aceita (reduzida se for VIP), fixada neste pedido
            $split = Commission::splitFor($amount, $offer->provider_profile_id);

            $offer->transitionTo(OfferState::Accepted, ['responded_at' => now()]);

            $losers = $request->offers()
                ->whereKeyNot($offer->id)
                ->whereIn('state', OfferState::open())
                ->with('provider')
                ->get();
            foreach ($losers as $loser) {
                $loser->transitionTo(OfferState::Lost, ['responded_at' => now()]);
            }

            // Sem pagamento antecipado: em dinheiro, ou por orçamento (paga-se quando o cliente aceitar o orçamento)
            $noUpfront = $request->isCash() || $request->pricing_mode === PricingMode::Quote;
            $request->by($offer->provider->user_id)->transitionTo(RequestState::Accepted, [
                'matched_at' => now(),
                'provider_profile_id' => $offer->provider_profile_id,
                'next_wave_at' => null,
                'amount' => $amount,
                'hourly_rate' => $hourly,
                'commission' => $split['commission'],
                'provider_net' => $split['provider_net'],
                'commission_percent' => $split['percent'],
                // Pagamento digital: o cliente paga na janela de pagamento e o valor fica retido (escrow)
                'payment_status' => $noUpfront ? null : ServiceRequest::PAYMENT_PENDING,
                'payment_due_at' => $noUpfront ? null : now()->addMinutes((int) config('longa.session_rules.payment_window_minutes')),
            ]);
            Conversation::query()->create(['service_request_id' => $request->id]);

            if ($noUpfront) {
                $this->lifecycle->record($request, 'scheduled', null, ['payment_method' => $request->payment_method]);
            } else {
                $this->lifecycle->record($request, 'payment_requested', null, ['amount' => $request->amount, 'due_at' => $request->payment_due_at?->toIso8601String()]);
            }

            RequestMatched::dispatch($request, $losers->map(fn (RequestOffer $o) => $o->provider->user_id)->values()->all());

            return ['request' => $request, 'new' => true];
        });

        if ($result['new']) {
            $this->audit->record('requests.offer_accepted', $result['request'], ['offer_id' => $offer->id]);
        }

        return $result['request'];
    }

    private function hourlyRateOf(ServiceRequest $request, RequestOffer $offer): int
    {
        $rate = DB::table('category_provider_profile')
            ->where('provider_profile_id', $offer->provider_profile_id)->where('category_id', $request->category_id)
            ->value('hourly_rate');

        if ($rate === null || ($request->max_hourly_rate !== null && (int) $rate > $request->max_hourly_rate)) {
            throw new ApiException('rate_not_allowed', 'O seu preço por hora está acima do máximo deste pedido.', 409);
        }

        return (int) $rate;
    }

    private function ensureCanAccept(ServiceRequest $request, RequestOffer $offer): void
    {
        if ($request->provider_profile_id !== null) {
            throw new ApiException('request_already_matched', 'Outro técnico aceitou este pedido primeiro.', 409);
        }

        if ($request->state === RequestState::Cancelled) {
            throw new ApiException('request_cancelled', 'O cliente cancelou este pedido.', 410);
        }

        if ($request->state !== RequestState::Searching || $request->expires_at?->isPast()) {
            throw new ApiException('request_closed', 'Este pedido já terminou.', 410);
        }

        if ($offer->state === OfferState::Declined) {
            throw new ApiException('offer_declined', 'Já recusou esta oferta.', 409);
        }

        if (! in_array($offer->state, OfferState::open(), true) || $offer->expires_at->lte(now())) {
            throw new ApiException('offer_expired', 'O prazo para aceitar terminou.', 410);
        }
    }
}
