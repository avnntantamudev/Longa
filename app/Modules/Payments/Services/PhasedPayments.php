<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Notifications\SessionUpdateNotification;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\EscrowHold;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentPhase;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Pagamento por fases (BISCA, Fase 3.2), só nos orçamentos com pagamento digital a partir do valor mínimo
 * (Definições). Cada fase é paga para o escrow antes do trabalho dessa fase e libertada ao técnico, com a comissão
 * retida, quando o cliente confirma a fase. A última fase é libertada na confirmação normal do serviço.
 */
class PhasedPayments
{
    public function __construct(
        private readonly PaymentProvider $gateway,
        private readonly EscrowService $escrow,
        private readonly SessionLifecycle $lifecycle,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Percentagens das fases para um orçamento (null = sem fases). Valida as propostas pelo técnico.
     *
     * @param  list<int>|null  $proposed
     * @return list<int>|null
     */
    public function phasesFor(int $total, bool $requested, ?array $proposed): ?array
    {
        if (! $requested) {
            return null;
        }
        $min = $this->settings->int('phased_min_total');
        if ($total < $min) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['phases' => ['O pagamento por fases só é possível a partir de '.number_format($min, 0, ',', '.').' Kz.']]);
        }
        $phases = $proposed ?? array_map('intval', (array) config('longa.payments.default_phases'));
        if (count($phases) < 2 || count($phases) > 5 || array_sum($phases) !== 100 || min($phases) < 10) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['phases' => ['Entre 2 e 5 fases, cada uma com pelo menos 10%, a somar 100%.']]);
        }

        return array_values($phases);
    }

    /**
     * Cria as fases quando o cliente aceita um orçamento com fases (a 1.ª fica a pagar).
     */
    public function createFor(ServiceRequest $request, Quote $quote): void
    {
        if ($quote->phases === null || $request->isCash()) {
            return;
        }

        $amount = $quote->amountDue();
        $assigned = 0;
        $last = count($quote->phases);
        foreach ($quote->phases as $i => $percent) {
            $phaseAmount = $i + 1 === $last ? $amount - $assigned : intdiv($amount * $percent, 100);
            $assigned += $phaseAmount;
            PaymentPhase::query()->create([
                'service_request_id' => $request->id, 'sequence' => $i + 1, 'percent' => $percent, 'amount' => $phaseAmount,
                'status' => $i === 0 ? PaymentPhase::DUE : PaymentPhase::PENDING,
            ]);
        }
    }

    public function dueOf(ServiceRequest $request): ?PaymentPhase
    {
        return PaymentPhase::query()->where('service_request_id', $request->id)->where('status', PaymentPhase::DUE)->first();
    }

    /**
     * Paga a fase a pagar: a 1.ª abre a retenção do escrow, as seguintes juntam-se a ela.
     */
    public function pay(ServiceRequest $request, User $client, string $method): ServiceRequest
    {
        $phase = $this->dueOf($request) ?? throw new ApiException('payment_not_expected', 'Não há nenhuma fase à espera de pagamento.', 409);

        $key = "charge:phase:{$phase->id}";
        $charge = PaymentCharge::query()->firstOrCreate(['idempotency_key' => $key], [
            'service_request_id' => $request->id, 'client_id' => $client->id, 'gateway' => $this->gateway->name(),
            'method' => $method, 'kind' => 'phase', 'amount' => $phase->amount, 'status' => ProviderResult::PENDING,
        ]);
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            $result = $this->gateway->charge($phase->amount, $client->phone, $method, $key, "BISCA · {$request->title} · fase {$phase->sequence}");
            $charge->forceFill(['status' => $result->status, 'external_reference' => $result->reference, 'failure_reason' => $result->failureReason, 'gateway_payload' => $result->payload ?: null])->save();
        }
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($charge->failure_reason ?? 'tente outro meio.'), 402);
        }

        $paid = DB::transaction(function () use ($request, $phase, $charge, $client) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            /** @var PaymentPhase $phase */
            $phase = PaymentPhase::query()->lockForUpdate()->findOrFail($phase->id);
            if ($phase->status !== PaymentPhase::DUE) {
                return $locked;
            }

            if (EscrowHold::query()->where('service_request_id', $locked->id)->exists()) {
                $this->escrow->topUp($locked, $charge, $phase->amount, "phase:{$phase->id}");
            } else {
                $this->escrow->hold($locked, $charge, $phase->amount);
            }
            $phase->forceFill(['status' => PaymentPhase::PAID, 'paid_at' => now(), 'payment_charge_id' => $charge->id])->save();
            $locked->forceFill(['payment_status' => ServiceRequest::PAYMENT_HELD])->save();
            $this->lifecycle->record($locked, 'phase_paid', $client, ['phase' => $phase->sequence, 'amount' => $phase->amount, 'charge_id' => $charge->id]);
            $this->audit->record('payments.phase_paid', $phase, ['amount' => $phase->amount], $client->id);

            return $locked;
        });
        $paid->provider->user->notify(new SessionUpdateNotification($paid, SessionUpdateNotification::PAYMENT_RECEIVED, (string) $phase->amount));

        return $paid;
    }

    /**
     * O cliente confirma uma fase (que não a última): libertada ao técnico com a comissão; a seguinte fica a pagar.
     */
    public function confirm(PaymentPhase $phase, User $client): PaymentPhase
    {
        return DB::transaction(function () use ($phase, $client) {
            /** @var PaymentPhase $phase */
            $phase = PaymentPhase::query()->lockForUpdate()->findOrFail($phase->id);
            /** @var ServiceRequest $request */
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($phase->service_request_id);
            if ($phase->status === PaymentPhase::RELEASED) {
                return $phase;
            }

            $next = PaymentPhase::query()->where('service_request_id', $request->id)->where('sequence', $phase->sequence + 1)->first();
            if ($phase->status !== PaymentPhase::PAID || $next === null) {
                throw new ApiException('phase_not_confirmable', $next === null
                    ? 'A última fase confirma-se com a confirmação do serviço.'
                    : 'Esta fase ainda não foi paga.', 409);
            }

            $this->escrow->releasePart($request, $phase->amount, "phase:{$phase->id}", $client);
            $phase->forceFill(['status' => PaymentPhase::RELEASED, 'released_at' => now()])->save();
            $next->forceFill(['status' => PaymentPhase::DUE])->save();
            $this->lifecycle->record($request, 'phase_confirmed', $client, ['phase' => $phase->sequence, 'amount' => $phase->amount]);
            $this->audit->record('payments.phase_released', $phase, ['amount' => $phase->amount], $client->id);

            return $phase;
        });
    }

    /**
     * Antes de concluir: todas as fases menos a última libertadas e a última paga.
     */
    public function readyToFinish(ServiceRequest $request): bool
    {
        $phases = PaymentPhase::query()->where('service_request_id', $request->id)->orderBy('sequence')->get();
        if ($phases->isEmpty()) {
            return true;
        }
        $last = $phases->pop();

        return $last->status === PaymentPhase::PAID && $phases->every(fn (PaymentPhase $p) => $p->status === PaymentPhase::RELEASED);
    }
}
