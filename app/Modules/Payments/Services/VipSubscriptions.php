<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Notifications\VipNotification;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\ProviderSubscription;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Subscrição VIP: pagamento pelo gateway (ou registo do admin: em mão / cortesia), período e ledger.
 *
 * Preço e duração vêm das Definições no momento da compra e ficam gravados na subscrição.
 * O período começa no fim do VIP em vigor (renovar antecipadamente não perde dias).
 * Sem reembolso a meio do período (avisado antes de pagar).
 */
class VipSubscriptions
{
    public function __construct(
        private readonly PaymentProvider $gateway,
        private readonly LedgerService $ledger,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fim do VIP em vigor (null se não for VIP).
     */
    public function activeUntil(ProviderProfile|int $provider): ?Carbon
    {
        $id = $provider instanceof ProviderProfile ? $provider->id : $provider;
        $active = ProviderSubscription::query()->where('provider_profile_id', $id)->where('status', ProviderSubscription::ACTIVE);

        if (! (clone $active)->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
            return null;
        }

        // Cada renovação começa no fim da anterior: o fim do VIP é o fim mais distante
        return Carbon::parse((string) $active->max('ends_at'));
    }

    public function isVip(ProviderProfile|int $provider): bool
    {
        return $this->activeUntil($provider) !== null;
    }

    /**
     * Compra pelo gateway. A chamada de rede fica fora das transações; a ativação é idempotente.
     */
    public function purchase(ProviderProfile $provider, User $user, string $method, string $idempotencyKey): ProviderSubscription
    {
        $key = "vip:{$provider->id}:{$idempotencyKey}";
        $subscription = $this->registerPending($provider, $method, $key);

        if ($subscription->status === ProviderSubscription::PENDING && $subscription->external_reference === null) {
            $result = $this->gateway->charge($subscription->amount, $user->phone, $method, $key, 'BISCA · Subscrição VIP');
            $subscription->forceFill([
                'gateway' => $this->gateway->name(),
                'external_reference' => $result->reference,
                'failure_reason' => $result->failureReason,
            ])->save();

            if ($result->status === ProviderResult::FAILED) {
                $subscription->forceFill(['status' => ProviderSubscription::FAILED])->save();
                $this->audit->record('vip.payment_failed', $subscription, ['reason' => $result->failureReason], $user->id);

                throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($result->failureReason ?? 'tente outro meio.'), 402);
            }

            if ($result->status === ProviderResult::SUCCEEDED) {
                $this->activate($subscription, Accounts::FUNDS_IN, $user);
            }
        }

        return $subscription->refresh();
    }

    /**
     * Registo do admin: pagamento recebido em mão (entra no ledger) ou cortesia (sem dinheiro).
     */
    public function grant(ProviderProfile $provider, User $admin, string $source, string $note): ProviderSubscription
    {
        $amount = $source === 'cash' ? $this->settings->int('vip_price') : 0;

        $subscription = ProviderSubscription::query()->create([
            'provider_profile_id' => $provider->id,
            'plan' => 'vip',
            'status' => ProviderSubscription::PENDING,
            'source' => $source,
            'amount' => $amount,
            'duration_days' => $this->settings->int('vip_duration_days'),
            'idempotency_key' => 'vip:grant:'.bin2hex(random_bytes(12)),
            'created_by' => $admin->id,
            'note' => $note,
        ]);

        return $this->activate($subscription, $source === 'cash' ? Accounts::CASH_SETTLEMENT : null, $admin);
    }

    private function registerPending(ProviderProfile $provider, string $method, string $key): ProviderSubscription
    {
        return DB::transaction(function () use ($provider, $method, $key) {
            // Serializa compras do mesmo técnico
            ProviderProfile::query()->whereKey($provider->id)->lockForUpdate()->first();

            $existing = ProviderSubscription::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $inFlight = ProviderSubscription::query()->where('provider_profile_id', $provider->id)
                ->where('status', ProviderSubscription::PENDING)->where('created_at', '>', now()->subMinutes(2))->exists();
            if ($inFlight) {
                throw new ApiException('payment_in_progress', 'Já há um pagamento VIP em curso. Aguarde um momento.', 409);
            }

            return ProviderSubscription::query()->create([
                'provider_profile_id' => $provider->id,
                'plan' => 'vip',
                'status' => ProviderSubscription::PENDING,
                'source' => 'electronic',
                'amount' => $this->settings->int('vip_price'),
                'duration_days' => $this->settings->int('vip_duration_days'),
                'method' => $method,
                'idempotency_key' => $key,
            ]);
        });
    }

    /**
     * Ativa: período a partir do fim do VIP em vigor e, se houve dinheiro, lançamento no ledger.
     */
    private function activate(ProviderSubscription $subscription, ?string $creditFrom, User $actor): ProviderSubscription
    {
        return DB::transaction(function () use ($subscription, $creditFrom, $actor) {
            ProviderProfile::query()->whereKey($subscription->provider_profile_id)->lockForUpdate()->first();
            /** @var ProviderSubscription $locked */
            $locked = ProviderSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status === ProviderSubscription::ACTIVE) {
                return $locked;
            }

            $currentEnd = ProviderSubscription::query()->where('provider_profile_id', $locked->provider_profile_id)
                ->where('status', ProviderSubscription::ACTIVE)->where('ends_at', '>', now())->max('ends_at');
            $start = $currentEnd === null ? now() : Carbon::parse((string) $currentEnd);

            $transactionId = null;
            if ($creditFrom !== null && $locked->amount > 0) {
                $transactionId = $this->ledger->post('vip_subscription', "vip:subscription:{$locked->id}", [
                    $creditFrom => -$locked->amount,
                    Accounts::SUBSCRIPTION_REVENUE => $locked->amount,
                ], $locked, ['source' => $locked->source, 'provider_profile_id' => $locked->provider_profile_id], $actor->id)->id;
            }

            $locked->forceFill([
                'status' => ProviderSubscription::ACTIVE,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDays($locked->duration_days),
                'ledger_transaction_id' => $transactionId,
            ])->save();

            $this->audit->record('vip.activated', $locked, ['source' => $locked->source, 'amount' => $locked->amount, 'ends_at' => $locked->ends_at?->toIso8601String()], $actor->id);
            $locked->provider->user->notify(new VipNotification(VipNotification::ACTIVATED, $locked->ends_at ?? now()));

            return $locked;
        });
    }
}
