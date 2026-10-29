<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\DisputeAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
class DisputeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->service_request_id,
            'opened_by' => $this->opened_by,
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel(),
            'description' => $this->description,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'provider_share' => $this->provider_share,
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn (DisputeAttachment $a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
            ])->values()),
        ];
    }
}
