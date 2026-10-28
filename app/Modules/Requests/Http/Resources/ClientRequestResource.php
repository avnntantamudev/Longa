<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Resources;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pedido visto pelo cliente que o publicou. Não mostra quais técnicos receberam a oferta;
 * os dados do técnico só aparecem no serviço, depois de ele aceitar.
 *
 * @mixin ServiceRequest
 */
class ClientRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'category' => ['id' => $this->category_id, 'name' => $this->category->name],
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'modality' => $this->modality,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'scheduled' => $this->scheduled,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'seconds_left' => $this->expires_at ? max(0, (int) now()->diffInSeconds($this->expires_at, false)) : null,
            'wave' => $this->wave,
            // Quantos técnicos estão a ver a oferta agora (sem dizer quem)
            'open_offers' => $this->offers()->whereIn('state', OfferState::open())->where('expires_at', '>', now())->count(),
            'session_id' => $this->session?->id,
            'cancel_reason' => $this->cancel_reason,
            'republished_from_id' => $this->republished_from_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
