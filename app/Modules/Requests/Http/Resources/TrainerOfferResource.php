<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Resources;

use App\Modules\Requests\Models\RequestOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Oferta vista pelo formador ANTES de aceitar: bairro e distância aproximada, nunca a localização
 * exata nem o telefone ou nome completo do aluno.
 *
 * @mixin RequestOffer
 */
class TrainerOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trainingRequest = $this->request;

        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'request_id' => $trainingRequest->id,
            'title' => $trainingRequest->title,
            'description' => $trainingRequest->description,
            'skill' => $trainingRequest->skill->name,
            'amount' => $trainingRequest->amount,
            'commission' => $trainingRequest->commission,
            'trainer_net' => $trainingRequest->trainer_net,
            'scheduled_at' => $trainingRequest->scheduled_at->toIso8601String(),
            'modality' => $trainingRequest->modality,
            'municipality' => $trainingRequest->municipality,
            'neighborhood' => $trainingRequest->neighborhood,
            // Arredondada aos 100 m para não permitir triangular a morada
            'distance_m' => (int) (round($this->distance_m / 100) * 100),
            'sent_at' => $this->sent_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'seconds_left' => $this->secondsLeft(),
            'session_id' => $this->when($this->state->value === 'accepted', fn () => $trainingRequest->session?->id),
        ];
    }
}
