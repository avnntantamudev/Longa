<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Resources;

use App\Modules\Requests\Models\QuoteAddendum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Adenda vista pelo cliente e pelo técnico.
 *
 * @mixin QuoteAddendum
 */
class AddendumResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => $this->amount,
            /** Adendas aprovadas + esta, em % do orçamento original */
            'percent_of_quote' => $this->percent_of_quote,
            /** pending_client | pending_team | approved | paid | rejected */
            'status' => $this->status,
            'status_label' => $this->label(),
            'team_review' => $this->team_review,
            'client_note' => $this->client_note,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
