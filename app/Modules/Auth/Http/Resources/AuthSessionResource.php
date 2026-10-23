<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuthSession
 */
class AuthSessionResource extends JsonResource
{
    public function __construct(AuthSession $resource, private readonly ?string $currentId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'device_name' => $this->device_name,
            'ip' => $this->ip,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'current' => $this->id === $this->currentId,
        ];
    }
}
