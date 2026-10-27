<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Notifications\VipNotification;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\TrainerSubscription;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Users\Models\TrainerProfile;
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
        private readonly PaymentProvider $provider,
        private readonly LedgerService $ledger,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fim do VIP em vigor (null se não for VIP).
     */
    public function activeUntil(TrainerProfile|int $trainer): ?Carbon
    {
        $id = $trainer instanceof TrainerProfile ? $trainer->id : $trainer;
        $active = TrainerSubscription::query()->where('trainer_profile_id', $id)->where('status', TrainerSubscription::ACTIVE);

        if (! (clone $active)->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
            return null;
        }

        // Cada renovação começa no fim da anterior: o fim do VIP é o fim mais distante
        return Carbon::parse((string) $active->max('ends_at'));
    }

    public function isVip(TrainerProfile|int $trainer): bool
    {
        return $this->activeUntil($trainer) !== null;
    }

    /**
     * Compra pelo gateway. A chamada de rede fica fora das transações; a ativação é idempotente.
     */
    public function purchase(TrainerProfile $trainer, User $user, string $method, string $idempotencyKey): TrainerSubscription
    {
        $key = "vip:{$trainer->id}:{$idempotencyKey}";
        $subscription = $this->registerPending($trainer, $method, $key);

        if ($subscription->status === TrainerSubscription::PENDING && $subscription->external_reference === null) {
            $result = $this->provider->charge($subscription->amount, $user->phone, $method, $key, 'Garimpo · Subscrição VIP');
            $subscription->forceFill([
                'provider' => $this->provider->name(),
                'external_reference' => $result->reference,
                'failure_reason' => $result->failureReason,
            ])->save();

            if ($result->status === ProviderResult::FAILED) {
                $subscription->forceFill(['status' => TrainerSubscription::FAILED])->save();
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
    public function grant(TrainerProfile $trainer, User $admin, string $source, string $note): TrainerSubscription
    {
        $amount = $source === 'cash' ? $this->settings->int('vip_price') : 0;

        $subscription = TrainerSubscription::query()->create([
            'trainer_profile_id' => $trainer->id,
            'plan' => 'vip',
            'status' => TrainerSubscription::PENDING,
            'source' => $source,
            'amount' => $amount,
            'duration_days' => $this->settings->int('vip_duration_days'),
            'idempotency_key' => 'vip:grant:'.bin2hex(random_bytes(12)),
            'created_by' => $admin->id,
            'note' => $note,
        ]);

        return $this->activate($subscription, $source === 'cash' ? Accounts::CASH_SETTLEMENT : null, $admin);
    }

    private function registerPending(TrainerProfile $trainer, string $method, string $key): TrainerSubscription
    {
        return DB::transaction(function () use ($trainer, $method, $key) {
            // Serializa compras do mesmo formador
            TrainerProfile::query()->whereKey($trainer->id)->lockForUpdate()->first();

            $existing = TrainerSubscription::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $inFlight = TrainerSubscription::query()->where('trainer_profile_id', $trainer->id)
                ->where('status', TrainerSubscription::PENDING)->where('created_at', '>', now()->subMinutes(2))->exists();
            if ($inFlight) {
                throw new ApiException('payment_in_progress', 'Já há um pagamento VIP em curso. Aguarde um momento.', 409);
            }

            return TrainerSubscription::query()->create([
                'trainer_profile_id' => $trainer->id,
                'plan' => 'vip',
                'status' => TrainerSubscription::PENDING,
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
    private function activate(TrainerSubscription $subscription, ?string $creditFrom, User $actor): TrainerSubscription
    {
        return DB::transaction(function () use ($subscription, $creditFrom, $actor) {
            TrainerProfile::query()->whereKey($subscription->trainer_profile_id)->lockForUpdate()->first();
            /** @var TrainerSubscription $locked */
            $locked = TrainerSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status === TrainerSubscription::ACTIVE) {
                return $locked;
            }

            $currentEnd = TrainerSubscription::query()->where('trainer_profile_id', $locked->trainer_profile_id)
                ->where('status', TrainerSubscription::ACTIVE)->where('ends_at', '>', now())->max('ends_at');
            $start = $currentEnd === null ? now() : Carbon::parse((string) $currentEnd);

            $transactionId = null;
            if ($creditFrom !== null && $locked->amount > 0) {
                $transactionId = $this->ledger->post('vip_subscription', "vip:subscription:{$locked->id}", [
                    $creditFrom => -$locked->amount,
                    Accounts::SUBSCRIPTION_REVENUE => $locked->amount,
                ], $locked, ['source' => $locked->source, 'trainer_profile_id' => $locked->trainer_profile_id], $actor->id)->id;
            }

            $locked->forceFill([
                'status' => TrainerSubscription::ACTIVE,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDays($locked->duration_days),
                'ledger_transaction_id' => $transactionId,
            ])->save();

            $this->audit->record('vip.activated', $locked, ['source' => $locked->source, 'amount' => $locked->amount, 'ends_at' => $locked->ends_at?->toIso8601String()], $actor->id);
            $locked->trainer->user->notify(new VipNotification(VipNotification::ACTIVATED, $locked->ends_at ?? now()));

            return $locked;
        });
    }
}
