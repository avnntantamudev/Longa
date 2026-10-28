<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\ProviderSubscription;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Técnico VIP (subscrição anual).
 *
 * @tags VIP
 */
class VipController extends Controller
{
    /**
     * Estado VIP, preço atual, vantagens e histórico.
     */
    public function show(Request $request, VipSubscriptions $vip, PlatformSettings $settings): JsonResponse
    {
        $provider = $this->provider($request);
        $until = $vip->activeUntil($provider);

        return response()->json(['data' => [
            'is_vip' => $until !== null,
            'vip_until' => $until?->toIso8601String(),
            'price' => $settings->int('vip_price'),
            'duration_days' => $settings->int('vip_duration_days'),
            'commission_percent' => $settings->int('vip_commission_percent'),
            'standard_commission_percent' => $settings->int('commission_percent'),
            'refundable' => false,
            'history' => ProviderSubscription::query()->where('provider_profile_id', $provider->id)->where('status', ProviderSubscription::ACTIVE)
                ->latest('id')->limit(10)->get()
                ->map(fn (ProviderSubscription $s) => [
                    'id' => $s->id, 'source' => $s->source, 'amount' => $s->amount,
                    'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(),
                ]),
        ]]);
    }

    /**
     * Comprar ou renovar o VIP.
     *
     * Requer `Idempotency-Key`. Renovar soma a duração ao fim do VIP em vigor. Sem reembolso a meio do período.
     * Erros: `payment_failed` (402), `payment_in_progress` (409), `vip_not_allowed` (409).
     */
    public function purchase(Request $request, VipSubscriptions $vip): JsonResponse
    {
        $data = $request->validate([
            /** @example multicaixa_express */
            'method' => ['required', 'in:multicaixa_express,reference'],
            'accept_terms' => ['accepted'],
        ], ['accept_terms.accepted' => 'Confirme que leu as condições (sem reembolso a meio do período).']);

        $provider = $this->provider($request);
        if ($provider->verification_status->value !== 'verified') {
            throw new ApiException('vip_not_allowed', 'Só técnicos verificados podem ser VIP.', 409);
        }

        /** @var User $user */
        $user = $request->user();
        $subscription = $vip->purchase($provider, $user, $data['method'], (string) $request->header('Idempotency-Key'));

        return response()->json(['data' => [
            'status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'amount' => $subscription->amount,
        ]]);
    }

    private function provider(Request $request): ProviderProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->providerProfile ?? throw new ApiException('profile_missing', 'Só técnicos podem ser VIP.', 409);
    }
}
