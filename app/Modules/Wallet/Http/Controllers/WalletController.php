<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Http\Resources\WalletMovementResource;
use App\Modules\Wallet\Http\Resources\WithdrawalResource;
use App\Modules\Wallet\Models\Withdrawal;
use App\Modules\Wallet\Services\WalletOperations;
use App\Modules\Wallet\Services\WalletSummary;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Carteira do técnico (só leitura).
 *
 * @tags Carteira
 */
class WalletController extends Controller
{
    /**
     * Saldos: disponível, pendente (serviços pagos por confirmar), total ganho e comissões em dívida (Kz).
     */
    public function show(Request $request, WalletSummary $wallet): JsonResponse
    {
        return response()->json(['data' => $wallet->summary($this->provider($request))]);
    }

    /**
     * Movimentos da carteira (paginação por cursor).
     */
    public function movements(Request $request, WalletSummary $wallet): AnonymousResourceCollection
    {
        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));

        return WalletMovementResource::collection($wallet->movements($this->provider($request), $perPage));
    }

    /**
     * Recarregar a carteira (Multicaixa Express, referência ou cartão). Requer `Idempotency-Key`.
     * As comissões em dívida são descontadas logo do valor recarregado.
     */
    public function topUp(Request $request, WalletOperations $wallet, WalletSummary $summary): JsonResponse
    {
        $data = $request->validate([
            /** Kz. @example 10000 */
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'method' => ['required', Rule::in(PaymentMethod::digitalValues())],
        ]);
        $provider = $this->provider($request);
        $wallet->topUp($provider, (int) $data['amount'], (string) $data['method'], (string) $request->header('Idempotency-Key'));

        return response()->json(['data' => $summary->summary($provider)], 201);
    }

    /**
     * Levantamentos do técnico.
     */
    public function withdrawals(Request $request): AnonymousResourceCollection
    {
        return WithdrawalResource::collection(Withdrawal::query()->where('provider_profile_id', $this->provider($request)->id)->latest('id')->paginate(20));
    }

    /**
     * Pedir levantamento (para IBAN ou Multicaixa Express). Requer `Idempotency-Key`. O valor fica retido até a
     * equipa pagar ou recusar.
     */
    public function withdraw(Request $request, WalletOperations $wallet): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            /** IBAN (AO06…) ou número Multicaixa Express. */
            'destination' => ['required', 'string', 'min:9', 'max:120'],
        ]);

        $withdrawal = $wallet->requestWithdrawal($this->provider($request), (int) $data['amount'], (string) $data['destination']);

        return (new WithdrawalResource($withdrawal))->response()->setStatusCode(201);
    }

    private function provider(Request $request): ProviderProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->providerProfile ?? throw new ApiException('forbidden', 'Só técnicos têm carteira.', 403);
    }
}
