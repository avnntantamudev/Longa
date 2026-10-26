<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\TrainerSubscription;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Formador VIP (subscrição anual).
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
        $trainer = $this->trainer($request);
        $until = $vip->activeUntil($trainer);

        return response()->json(['data' => [
            'is_vip' => $until !== null,
            'vip_until' => $until?->toIso8601String(),
            'price' => $settings->int('vip_price'),
            'duration_days' => $settings->int('vip_duration_days'),
            'commission_percent' => $settings->int('vip_commission_percent'),
            'standard_commission_percent' => $settings->int('commission_percent'),
            'refundable' => false,
            'history' => TrainerSubscription::query()->where('trainer_profile_id', $trainer->id)->where('status', TrainerSubscription::ACTIVE)
                ->latest('id')->limit(10)->get()
                ->map(fn (TrainerSubscription $s) => [
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

        $trainer = $this->trainer($request);
        if ($trainer->verification_status->value !== 'verified') {
            throw new ApiException('vip_not_allowed', 'Só formadores verificados podem ser VIP.', 409);
        }

        /** @var User $user */
        $user = $request->user();
        $subscription = $vip->purchase($trainer, $user, $data['method'], (string) $request->header('Idempotency-Key'));

        return response()->json(['data' => [
            'status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'amount' => $subscription->amount,
        ]]);
    }

    private function trainer(Request $request): TrainerProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->trainerProfile ?? throw new ApiException('profile_missing', 'Só formadores podem ser VIP.', 409);
    }
}
