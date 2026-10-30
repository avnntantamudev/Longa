<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase de pagamento de um orçamento grande: pending (por vir) → due (o cliente tem de pagar antes do trabalho
 * da fase) → paid (retida no escrow) → released (libertada ao técnico quando o cliente confirma a fase).
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $sequence
 * @property int $percent
 * @property int $amount
 * @property string $status
 * @property int|null $payment_charge_id
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $released_at
 * @property-read ServiceRequest $request
 */
#[Fillable(['service_request_id', 'sequence', 'percent', 'amount', 'status'])]
class PaymentPhase extends Model
{
    public const PENDING = 'pending';

    public const DUE = 'due';

    public const PAID = 'paid';

    public const RELEASED = 'released';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'percent' => 'integer', 'amount' => 'integer', 'paid_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }
}
