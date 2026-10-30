<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Payments\Services\PhasedPayments;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Serviços por orçamento (BISCA, Fase 2.5):
 *
 *  1. o técnico chega e começa o diagnóstico (aceite/a caminho → em diagnóstico); a taxa de visita, quando a
 *     categoria a tem, é paga em mão ao técnico e a comissão sobre ela fica em dívida;
 *  2. o técnico envia o orçamento (mão de obra, materiais, prazo, validade) → orçamento enviado;
 *  3. o cliente aceita (preço bloqueado; paga o total menos a taxa de visita), pede revisão (volta ao diagnóstico)
 *     ou recusa (cancelado; o técnico fica com a taxa de visita);
 *  4. orçamento sem resposta até à validade → expirado e pedido cancelado.
 *
 * Orçamentos muito fora do intervalo de referência da categoria ficam marcados para o alerta do painel.
 * Cada orçamento guarda o técnico, para a opção futura de vários orçamentos por pedido (longa.quotes.max_per_request).
 */
class QuoteFlow
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly CommissionDebts $debts,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
        private readonly PhasedPayments $phased,
    ) {}

    public function startDiagnosis(ServiceRequest $request, User $provider): ServiceRequest
    {
        return DB::transaction(function () use ($request, $provider) {
            $locked = $this->lock($request);
            $this->ensureQuoteMode($locked);

            if ($locked->state === RequestState::Diagnosing) {
                return $locked;
            }

            if ($locked->state === RequestState::Accepted) {
                $this->lifecycle->transition($locked, RequestState::OnTheWay, 'on_the_way', $provider, [], ['on_the_way_at' => now()]);
            }

            $this->lifecycle->transition($locked, RequestState::Diagnosing, 'diagnosis_started', $provider, ['visit_fee' => $locked->visit_fee], [
                'diagnosis_started_at' => now(),
            ]);

            if ($locked->visit_fee > 0) {
                $this->debts->registerVisitFee($locked, $provider);
                $this->audit->record('payments.visit_fee_commission', $locked, ['visit_fee' => $locked->visit_fee], $provider->id);
            }

            return $locked;
        });
    }

    /**
     * @param  array{labour_amount: int, materials_amount?: int, materials?: list<array{descricao: string, quantidade: int, valor: int}>|null, execution_days: int, notes?: string|null, phased?: bool, phases?: list<int>|null}  $data
     */
    public function submit(ServiceRequest $request, User $provider, array $data): Quote
    {
        $quote = DB::transaction(function () use ($request, $provider, $data) {
            $locked = $this->lock($request);
            $this->ensureQuoteMode($locked);

            if ($locked->state !== RequestState::Diagnosing) {
                throw new ApiException('quote_not_expected', 'Comece o diagnóstico antes de enviar o orçamento.', 409);
            }

            $previous = $locked->quotes()->where('provider_profile_id', $locked->provider_profile_id)->latest('id')->first();
            $total = $data['labour_amount'] + ($data['materials_amount'] ?? 0);

            $quote = Quote::query()->create([
                ...collect($data)->except(['phased', 'phases'])->all(),
                'phases' => $this->phased->phasesFor($total, (bool) ($data['phased'] ?? false), $data['phases'] ?? null),
                'service_request_id' => $locked->id,
                'provider_profile_id' => (int) $locked->provider_profile_id,
                'revision' => ($previous->revision ?? 0) + 1,
                'total' => $total,
                'visit_fee_deducted' => min($locked->visit_fee, $total),
                'valid_until' => now()->addDays($this->settings->int('quote_validity_days')),
                'outside_reference' => $this->outsideReference($locked, $total),
            ]);

            $this->lifecycle->transition($locked, RequestState::QuoteSent, 'quote_sent', $provider, [
                'quote_id' => $quote->id, 'total' => $total, 'revision' => $quote->revision,
            ]);

            return $quote;
        });

        $this->audit->record('quotes.sent', $quote, ['total' => $quote->total, 'outside_reference' => $quote->outside_reference], $provider->id);
        $quote->request->client->notify(new SessionUpdateNotification($quote->request, SessionUpdateNotification::QUOTE_SENT, (string) $quote->amountDue()));

        return $quote;
    }

    /**
     * O cliente aceita: o preço fica bloqueado. Paga o total menos a taxa de visita já paga em mão
     * (pagamento digital: retido no escrow na janela de pagamento; em dinheiro: no fim, ao técnico).
     */
    public function accept(Quote $quote, User $client): ServiceRequest
    {
        $request = DB::transaction(function () use ($quote, $client) {
            [$locked, $quote] = $this->lockWithQuote($quote);

            if ($quote->status === Quote::ACCEPTED) {
                return $locked;
            }
            $this->ensureOpen($locked, $quote);

            $amount = $quote->amountDue();
            $split = Commission::splitFor($amount, (int) $locked->provider_profile_id);
            $electronic = ! $locked->isCash() && $amount > 0;

            $quote->forceFill(['status' => Quote::ACCEPTED, 'responded_at' => now()])->save();
            $this->phased->createFor($locked, $quote);
            $this->lifecycle->transition($locked, RequestState::QuoteAccepted, 'quote_accepted', $client, [
                'quote_id' => $quote->id, 'total' => $quote->total, 'amount_due' => $amount,
            ], [
                'amount' => $amount,
                'commission' => $split['commission'],
                'provider_net' => $split['provider_net'],
                'commission_percent' => $split['percent'],
                'payment_status' => $electronic ? ServiceRequest::PAYMENT_PENDING : null,
                // Sem prazo: o preço está bloqueado e "orçamento aceite" só pode seguir para "em execução"
                'payment_due_at' => null,
            ]);

            return $locked;
        });

        $this->audit->record('quotes.accepted', $quote, ['total' => $quote->total], $client->id);
        $request->provider->user->notify(new SessionUpdateNotification($request, SessionUpdateNotification::QUOTE_ACCEPTED));

        return $request;
    }

    public function requestRevision(Quote $quote, User $client, ?string $note): ServiceRequest
    {
        $request = DB::transaction(function () use ($quote, $client, $note) {
            [$locked, $quote] = $this->lockWithQuote($quote);
            $this->ensureOpen($locked, $quote);

            $quote->forceFill(['status' => Quote::REVISION_REQUESTED, 'client_note' => $note, 'responded_at' => now()])->save();
            $this->lifecycle->transition($locked, RequestState::Diagnosing, 'quote_revision_requested', $client, ['quote_id' => $quote->id, 'note' => $note]);

            return $locked;
        });
        $request->provider->user->notify(new SessionUpdateNotification($request, SessionUpdateNotification::QUOTE_REVISION, $note));

        return $request;
    }

    /**
     * O cliente recusa: o pedido é cancelado e o técnico fica com a taxa de visita (já paga em mão).
     */
    public function refuse(Quote $quote, User $client, ?string $note): ServiceRequest
    {
        $request = DB::transaction(function () use ($quote, $client, $note) {
            [$locked, $quote] = $this->lockWithQuote($quote);
            $this->ensureOpen($locked, $quote);

            $quote->forceFill(['status' => Quote::REFUSED, 'client_note' => $note, 'responded_at' => now()])->save();
            $this->lifecycle->transition($locked, RequestState::Cancelled, 'quote_refused', $client, ['quote_id' => $quote->id, 'note' => $note], [
                'closed_reason' => 'quote_refused', 'cancelled_by' => $client->id, 'cancel_reason' => $note, 'cancelled_at' => now(),
            ]);

            return $locked;
        });
        $this->audit->record('quotes.refused', $quote, ['total' => $quote->total, 'note' => $note], $client->id);
        $request->provider->user->notify(new SessionUpdateNotification($request, SessionUpdateNotification::QUOTE_REFUSED));

        return $request;
    }

    /**
     * Orçamentos enviados sem resposta até à validade: expiram e o pedido é cancelado (scheduler, a cada minuto).
     */
    public function expireDue(): int
    {
        $count = 0;
        $ids = Quote::query()->where('status', Quote::SENT)->where('valid_until', '<=', now())->limit(200)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                /** @var Quote $quote */
                $quote = Quote::query()->lockForUpdate()->findOrFail($id);
                $request = ServiceRequest::query()->lockForUpdate()->findOrFail($quote->service_request_id);
                if ($quote->status !== Quote::SENT || $request->state !== RequestState::QuoteSent) {
                    return;
                }
                $quote->forceFill(['status' => Quote::EXPIRED])->save();
                $this->lifecycle->transition($request, RequestState::Cancelled, 'quote_expired', null, ['quote_id' => $quote->id], [
                    'closed_reason' => 'quote_expired', 'cancelled_at' => now(),
                ]);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Muito fora do intervalo de referência: abaixo de mínimo × (1 − N%) ou acima de máximo × (1 + N%).
     */
    public function outsideReference(ServiceRequest $request, int $total): bool
    {
        $price = $request->category->effectivePrice();
        if ($price === null || $price->reference_min === null || $price->reference_max === null) {
            return false;
        }
        $tolerance = $this->settings->int('quote_outlier_percent');

        return $total * 100 < $price->reference_min * (100 - $tolerance) || $total * 100 > $price->reference_max * (100 + $tolerance);
    }

    private function lock(ServiceRequest $request): ServiceRequest
    {
        return ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
    }

    /**
     * @return array{0: ServiceRequest, 1: Quote}
     */
    private function lockWithQuote(Quote $quote): array
    {
        $request = ServiceRequest::query()->lockForUpdate()->findOrFail($quote->service_request_id);

        return [$request, Quote::query()->lockForUpdate()->findOrFail($quote->id)];
    }

    private function ensureQuoteMode(ServiceRequest $request): void
    {
        if ($request->pricing_mode !== PricingMode::Quote) {
            throw new ApiException('not_quote_request', 'Este serviço não é por orçamento.', 409);
        }
    }

    private function ensureOpen(ServiceRequest $request, Quote $quote): void
    {
        if ($quote->status !== Quote::SENT || $request->state !== RequestState::QuoteSent) {
            throw new ApiException('quote_not_open', 'Este orçamento já não está à espera de resposta.', 409);
        }
        if ($quote->valid_until->isPast()) {
            throw new ApiException('quote_expired', 'O orçamento expirou.', 410);
        }
    }
}
