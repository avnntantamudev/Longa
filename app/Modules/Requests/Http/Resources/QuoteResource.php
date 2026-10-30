<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Resources;

use App\Modules\Requests\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Orçamento visto pelo cliente e pelo técnico.
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            /** sent | accepted | refused | revision_requested | expired | superseded */
            'status' => $this->status,
            'labour_amount' => $this->labour_amount,
            'materials_amount' => $this->materials_amount,
            'materials' => $this->materials ?? [],
            'total' => $this->total,
            /** Taxa de visita já paga em mão, descontada no valor a pagar */
            'visit_fee_deducted' => $this->visit_fee_deducted,
            'amount_due' => $this->amountDue(),
            'execution_days' => $this->execution_days,
            /** Percentagens das fases de pagamento (null = pagamento único) */
            'phases' => $this->phases,
            'notes' => $this->notes,
            'valid_until' => $this->valid_until->toIso8601String(),
            'client_note' => $this->client_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
