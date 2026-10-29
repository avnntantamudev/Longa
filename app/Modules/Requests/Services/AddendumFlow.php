<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Services\EscrowService;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Adendas ao orçamento aceite (BISCA, Fase 2.5):
 *
 *  1. durante a execução, o técnico submete o trabalho extra (descrição e valor) → à espera do cliente;
 *  2. o cliente aprova ou recusa, antes de o trabalho ser feito;
 *  3. se as adendas aprovadas mais esta passarem N% do orçamento original (Definições), a aprovação do cliente
 *     envia-a para revisão da equipa, que aprova ou recusa (com nota, auditado);
 *  4. aprovada: o valor do pedido sobe e a comissão é recalculada. Pagamento digital: o cliente paga a adenda e o
 *     valor junta-se ao escrow (paga); em dinheiro, paga tudo ao técnico no fim.
 */
class AddendumFlow
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly EscrowService $escrow,
        private readonly PaymentProvider $gateway,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(ServiceRequest $request, User $provider, string $description, int $amount): QuoteAddendum
    {
        $addendum = DB::transaction(function () use ($request, $provider, $description, $amount) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $quote = $locked->quotes()->where('status', Quote::ACCEPTED)->first();

            if ($locked->state !== RequestState::InProgress || $quote === null) {
                throw new ApiException('addendum_not_allowed', 'Só pode propor trabalho extra durante a execução de um orçamento aceite.', 409);
            }
            if ($locked->addenda()->whereIn('status', [QuoteAddendum::PENDING_CLIENT, QuoteAddendum::PENDING_TEAM])->exists()) {
                throw new ApiException('addendum_pending', 'Já há uma adenda à espera de resposta.', 409);
            }

            $approved = (int) $locked->addenda()->whereIn('status', [QuoteAddendum::APPROVED, QuoteAddendum::PAID])->sum('amount');
            $percent = (int) ceil(($approved + $amount) * 100 / max(1, $quote->total));

            $addendum = QuoteAddendum::query()->create([
                'service_request_id' => $locked->id,
                'quote_id' => $quote->id,
                'provider_profile_id' => (int) $locked->provider_profile_id,
                'description' => $description,
                'amount' => $amount,
                'percent_of_quote' => $percent,
                'team_review' => $percent > $this->settings->int('addendum_review_percent'),
            ]);
            $this->lifecycle->record($locked, 'addendum_submitted', $provider, ['addendum_id' => $addendum->id, 'amount' => $amount, 'percent_of_quote' => $percent]);

            return $addendum;
        });

        $this->audit->record('addenda.submitted', $addendum, ['amount' => $amount, 'percent_of_quote' => $addendum->percent_of_quote], $provider->id);

        return $addendum;
    }

    /**
     * O cliente aprova: acima do limite vai para a equipa; senão fica aprovada.
     */
    public function clientApprove(QuoteAddendum $addendum, User $client): QuoteAddendum
    {
        return DB::transaction(function () use ($addendum, $client) {
            [$request, $addendum] = $this->lock($addendum);
            if ($addendum->status !== QuoteAddendum::PENDING_CLIENT) {
                return $this->unchangedOrFail($addendum, [QuoteAddendum::PENDING_TEAM, QuoteAddendum::APPROVED, QuoteAddendum::PAID]);
            }

            $addendum->forceFill(['client_decided_at' => now()])->save();
            if ($addendum->team_review) {
                $addendum->forceFill(['status' => QuoteAddendum::PENDING_TEAM])->save();
                $this->lifecycle->record($request, 'addendum_team_review', $client, ['addendum_id' => $addendum->id, 'percent_of_quote' => $addendum->percent_of_quote]);
            } else {
                $this->apply($request, $addendum, $client);
            }

            return $addendum;
        });
    }

    public function clientReject(QuoteAddendum $addendum, User $client, ?string $note): QuoteAddendum
    {
        return DB::transaction(function () use ($addendum, $client, $note) {
            [$request, $addendum] = $this->lock($addendum);
            if ($addendum->status !== QuoteAddendum::PENDING_CLIENT) {
                return $this->unchangedOrFail($addendum, [QuoteAddendum::REJECTED]);
            }

            $addendum->forceFill(['status' => QuoteAddendum::REJECTED, 'client_decided_at' => now(), 'client_note' => $note])->save();
            $this->lifecycle->record($request, 'addendum_rejected', $client, ['addendum_id' => $addendum->id, 'by' => 'client']);

            return $addendum;
        });
    }

    /**
     * Revisão da equipa (adendas acima do limite, já aprovadas pelo cliente).
     */
    public function teamReview(QuoteAddendum $addendum, User $reviewer, bool $approve, string $note): QuoteAddendum
    {
        $result = DB::transaction(function () use ($addendum, $reviewer, $approve, $note) {
            [$request, $addendum] = $this->lock($addendum);
            if ($addendum->status !== QuoteAddendum::PENDING_TEAM) {
                throw new ApiException('addendum_not_in_review', 'Esta adenda já não está em revisão.', 409);
            }

            $addendum->forceFill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            if ($approve) {
                $this->apply($request, $addendum, $reviewer);
            } else {
                $addendum->forceFill(['status' => QuoteAddendum::REJECTED])->save();
                $this->lifecycle->record($request, 'addendum_rejected', $reviewer, ['addendum_id' => $addendum->id, 'by' => 'team', 'note' => $note]);
            }

            return $addendum;
        });

        $this->audit->record($approve ? 'addenda.team_approved' : 'addenda.team_rejected', $result, ['note' => $note, 'amount' => $result->amount], $reviewer->id);

        return $result;
    }

    /**
     * Pagamento digital da adenda aprovada: cobra o cliente e junta o valor ao escrow.
     */
    public function pay(QuoteAddendum $addendum, User $client, string $method): QuoteAddendum
    {
        $request = $addendum->request;
        if ($addendum->status === QuoteAddendum::PAID) {
            return $addendum;
        }
        if ($addendum->status !== QuoteAddendum::APPROVED || $request->isCash()) {
            throw new ApiException('addendum_not_payable', 'Esta adenda não está à espera de pagamento.', 409);
        }

        $key = "charge:addendum:{$addendum->id}";
        $charge = PaymentCharge::query()->firstOrCreate(['idempotency_key' => $key], [
            'service_request_id' => $request->id, 'client_id' => $client->id, 'gateway' => $this->gateway->name(),
            'method' => $method, 'kind' => 'addendum', 'amount' => $addendum->amount, 'status' => ProviderResult::PENDING,
        ]);
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            $result = $this->gateway->charge($addendum->amount, $client->phone, $method, $key, "BISCA · adenda #{$addendum->id}");
            $charge->forceFill(['status' => $result->status, 'external_reference' => $result->reference, 'failure_reason' => $result->failureReason])->save();
        }
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($charge->failure_reason ?? 'tente outro meio.'), 402);
        }

        return DB::transaction(function () use ($addendum, $request, $charge, $client) {
            [, $addendum] = $this->lock($addendum);
            if ($addendum->status === QuoteAddendum::PAID) {
                return $addendum;
            }
            $this->escrow->topUp($request, $charge, $addendum->amount, "addendum:{$addendum->id}");
            $addendum->forceFill(['status' => QuoteAddendum::PAID])->save();
            $this->lifecycle->record($request, 'addendum_paid', $client, ['addendum_id' => $addendum->id, 'amount' => $addendum->amount, 'charge_id' => $charge->id]);

            return $addendum;
        });
    }

    /**
     * Adenda aprovada: o valor do pedido sobe e a comissão é recalculada com a percentagem do pedido.
     */
    private function apply(ServiceRequest $request, QuoteAddendum $addendum, User $actor): void
    {
        $amount = $request->amount + $addendum->amount;
        $split = Commission::split($amount, $request->commission_percent);
        $request->forceFill(['amount' => $amount, 'commission' => $split['commission'], 'provider_net' => $split['provider_net']])->save();
        $addendum->forceFill(['status' => QuoteAddendum::APPROVED])->save();

        $this->lifecycle->record($request, 'addendum_approved', $actor, ['addendum_id' => $addendum->id, 'amount' => $addendum->amount, 'new_total' => $amount]);
    }

    /**
     * @return array{0: ServiceRequest, 1: QuoteAddendum}
     */
    private function lock(QuoteAddendum $addendum): array
    {
        $request = ServiceRequest::query()->lockForUpdate()->findOrFail($addendum->service_request_id);

        return [$request, QuoteAddendum::query()->lockForUpdate()->findOrFail($addendum->id)];
    }

    /**
     * Repetir a mesma decisão devolve a adenda; uma decisão diferente da já tomada é recusada.
     *
     * @param  list<string>  $sameOutcome
     */
    private function unchangedOrFail(QuoteAddendum $addendum, array $sameOutcome): QuoteAddendum
    {
        if (in_array($addendum->status, $sameOutcome, true)) {
            return $addendum;
        }

        throw new ApiException('addendum_decided', 'Esta adenda já foi decidida.', 409);
    }
}
