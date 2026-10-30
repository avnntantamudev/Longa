<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'destination' => $this->destination,
            /** requested | paid | rejected */
            'status' => $this->status,
            'status_label' => $this->label(),
            'note' => $this->note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
