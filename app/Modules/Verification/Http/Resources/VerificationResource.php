<?php

declare(strict_types=1);

namespace App\Modules\Verification\Http\Resources;

use App\Modules\Verification\Models\Verification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O nº do BI só aparece mascarado; o número completo nunca sai na API.
 *
 * @mixin Verification
 */
class VerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'document_number_masked' => $this->maskedDocumentNumber(),
            'documents_count' => count($this->documents ?? []),
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
        ];
    }
}
