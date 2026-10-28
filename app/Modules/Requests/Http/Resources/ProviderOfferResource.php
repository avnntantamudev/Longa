<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Resources;

use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Services\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Oferta vista pelo formador ANTES de aceitar: bairro e distância aproximada, nunca a localização
 * exata nem o telefone ou nome completo do aluno.
 *
 * @mixin RequestOffer
 */
class ProviderOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serviceRequest = $this->request;
        $split = Commission::splitFor($serviceRequest->amount, $this->provider_profile_id);

        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'request_id' => $serviceRequest->id,
            'scheduled' => $serviceRequest->scheduled,
            'title' => $serviceRequest->title,
            'description' => $serviceRequest->description,
            'category' => $serviceRequest->category->name,
            'amount' => $serviceRequest->amount,
            'commission' => $split['commission'],
            'commission_percent' => $split['percent'],
            'provider_net' => $split['provider_net'],
            'scheduled_at' => $serviceRequest->scheduled_at->toIso8601String(),
            'modality' => $serviceRequest->modality,
            'province' => $serviceRequest->province,
            'municipality' => $serviceRequest->municipality,
            'neighborhood' => $serviceRequest->neighborhood,
            // Arredondada aos 100 m para não permitir triangular a morada
            'distance_m' => (int) (round($this->distance_m / 100) * 100),
            'sent_at' => $this->sent_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'seconds_left' => $this->secondsLeft(),
            'session_id' => $this->when($this->state->value === 'accepted', fn () => $serviceRequest->session?->id),
        ];
    }
}
