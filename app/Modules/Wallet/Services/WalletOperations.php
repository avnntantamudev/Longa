<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Services;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Payments\Support\ProviderResult;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Events\WalletUpdated;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerService;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Modules\Wallet\Models\Withdrawal;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Operações da carteira do técnico (BISCA, Fase 3.1). Cada operação é uma transação do livro-razão com chave de
 * idempotência: ou grava todas as linhas ou nenhuma, e repetir a mesma chave não volta a mexer no dinheiro.
 *
 *  - recarga: o técnico paga (Multicaixa, referência, cartão) e o valor entra no saldo; as comissões em dívida são
 *    descontadas logo;
 *  - levantamento: o valor sai do saldo para "levantamentos pendentes"; a equipa paga (sai da plataforma) ou recusa
 *    (estorno para o saldo);
 *  - ajuste: correção feita pela equipa, sempre como movimento novo (nunca altera linhas anteriores).
 */
class WalletOperations
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CommissionDebts $debts,
        private readonly PaymentProvider $gateway,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function topUp(ProviderProfile $provider, int $amount, string $method, string $key): LedgerTransaction
    {
        if ($amount < (int) config('longa.wallet.min_topup')) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['amount' => ['A recarga mínima é de '.number_format((int) config('longa.wallet.min_topup'), 0, ',', '.').' Kz.']]);
        }

        $chargeKey = "charge:topup:{$provider->id}:{$key}";
        $charge = PaymentCharge::query()->firstOrCreate(['idempotency_key' => $chargeKey], [
            'service_request_id' => null, 'client_id' => $provider->user_id, 'gateway' => $this->gateway->name(),
            'method' => $method, 'kind' => 'topup', 'amount' => $amount, 'status' => ProviderResult::PENDING,
        ]);
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            $result = $this->gateway->charge($amount, $provider->user->phone, $method, $chargeKey, 'BISCA · recarga da carteira');
            $charge->forceFill(['status' => $result->status, 'external_reference' => $result->reference, 'failure_reason' => $result->failureReason])->save();
        }
        if ($charge->status !== ProviderResult::SUCCEEDED) {
            throw new ApiException('payment_failed', 'O pagamento não foi aceite: '.($charge->failure_reason ?? 'tente outro meio.'), 402);
        }

        $transaction = $this->ledger->post('wallet_topup', "wallet:topup:{$charge->id}", [
            Accounts::providerAvailable($provider) => $charge->amount,
            Accounts::FUNDS_IN => -$charge->amount,
        ], $provider, ['charge_id' => $charge->id, 'method' => $method], $provider->user_id);

        $this->debts->offset($provider->id, "topup:{$charge->id}");
        $this->audit->record('wallet.topped_up', $transaction, ['amount' => $charge->amount, 'provider_profile_id' => $provider->id], $provider->user_id);
        WalletUpdated::dispatch($provider->user_id, 'topup', null);

        return $transaction;
    }

    public function requestWithdrawal(ProviderProfile $provider, int $amount, string $destination): Withdrawal
    {
        $min = $this->settings->int('withdrawal_min_amount');
        if ($amount < $min) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['amount' => ['O levantamento mínimo é de '.number_format($min, 0, ',', '.').' Kz.']]);
        }

        // Primeiro paga-se a dívida de comissões com o saldo; o resto pode ser levantado
        $this->debts->offset($provider->id, 'withdrawal:'.now()->format('YmdHisv'));
        $available = Accounts::providerAvailable($provider);

        $withdrawal = $this->ledger->withLockedAccounts([$available], function () use ($provider, $amount, $destination, $available) {
            if ($this->ledger->balance($available) < $amount) {
                throw new ApiException('insufficient_balance', 'Saldo insuficiente para este levantamento.', 422);
            }

            return DB::transaction(function () use ($provider, $amount, $destination, $available) {
                $withdrawal = Withdrawal::query()->create(['provider_profile_id' => $provider->id, 'amount' => $amount, 'destination' => $destination]);
                $this->ledger->post('withdrawal_requested', "wallet:withdrawal:{$withdrawal->id}:requested", [
                    $available => -$amount,
                    Accounts::WITHDRAWALS_PENDING => $amount,
                ], $withdrawal, ['destination' => $destination], $provider->user_id);

                return $withdrawal;
            });
        });

        $this->audit->record('wallet.withdrawal_requested', $withdrawal, ['amount' => $amount], $provider->user_id);
        WalletUpdated::dispatch($provider->user_id, 'withdrawal_requested', null);

        return $withdrawal;
    }

    public function payWithdrawal(Withdrawal $withdrawal, User $admin, string $reference): Withdrawal
    {
        $result = $this->decide($withdrawal, $admin, Withdrawal::PAID, function (Withdrawal $w) use ($admin, $reference) {
            $this->ledger->post('withdrawal_paid', "wallet:withdrawal:{$w->id}:paid", [
                Accounts::WITHDRAWALS_PENDING => -$w->amount,
                Accounts::PAYOUTS => $w->amount,
            ], $w, ['reference' => $reference], $admin->id);
            $w->forceFill(['payout_reference' => $reference])->save();
        });
        WalletUpdated::dispatch($result->provider->user_id, 'withdrawal_paid', null);

        return $result;
    }

    public function rejectWithdrawal(Withdrawal $withdrawal, User $admin, string $note): Withdrawal
    {
        $result = $this->decide($withdrawal, $admin, Withdrawal::REJECTED, function (Withdrawal $w) use ($admin, $note) {
            // Estorno: o valor volta ao saldo disponível
            $this->ledger->post('withdrawal_rejected', "wallet:withdrawal:{$w->id}:rejected", [
                Accounts::WITHDRAWALS_PENDING => -$w->amount,
                Accounts::providerAvailable($w->provider_profile_id) => $w->amount,
            ], $w, ['note' => $note], $admin->id);
            $w->forceFill(['note' => $note])->save();
        });
        WalletUpdated::dispatch($result->provider->user_id, 'withdrawal_rejected', null);

        return $result;
    }

    /**
     * Ajuste da equipa (positivo credita, negativo debita o saldo disponível). Nunca altera movimentos anteriores.
     */
    public function adjust(ProviderProfile $provider, int $amount, string $reason, User $admin, string $key): LedgerTransaction
    {
        if ($amount === 0) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['amount' => ['O ajuste não pode ser 0.']]);
        }

        $transaction = $this->ledger->post('adjustment', "wallet:adjustment:{$key}", [
            Accounts::providerAvailable($provider) => $amount,
            Accounts::ADJUSTMENTS => -$amount,
        ], $provider, ['reason' => $reason], $admin->id);

        $this->audit->record('wallet.adjusted', $transaction, ['amount' => $amount, 'reason' => $reason, 'provider_profile_id' => $provider->id], $admin->id);
        WalletUpdated::dispatch($provider->user_id, 'adjustment', null);

        return $transaction;
    }

    /**
     * @param  callable(Withdrawal): void  $apply
     */
    private function decide(Withdrawal $withdrawal, User $admin, string $status, callable $apply): Withdrawal
    {
        $result = DB::transaction(function () use ($withdrawal, $admin, $status, $apply) {
            /** @var Withdrawal $locked */
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($locked->status === $status) {
                return $locked;
            }
            if ($locked->status !== Withdrawal::REQUESTED) {
                throw new ApiException('withdrawal_decided', 'Este levantamento já foi decidido.', 409);
            }

            $apply($locked);
            $locked->forceFill(['status' => $status, 'decided_by' => $admin->id, 'decided_at' => now()])->save();

            return $locked;
        });

        $this->audit->record($status === Withdrawal::PAID ? 'wallet.withdrawal_paid' : 'wallet.withdrawal_rejected', $result, ['amount' => $result->amount], $admin->id);

        return $result;
    }
}
