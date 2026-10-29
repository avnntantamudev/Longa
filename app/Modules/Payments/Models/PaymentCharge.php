<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido ao gateway (cobrança ou reembolso) e a respetiva resposta.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $client_id
 * @property string $gateway
 * @property string $method
 * @property string $kind
 * @property int $amount
 * @property string $status
 * @property string|null $external_reference
 * @property string $idempotency_key
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $gateway_payload
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['service_request_id', 'client_id', 'gateway', 'method', 'kind', 'amount', 'status', 'external_reference', 'idempotency_key', 'failure_reason', 'gateway_payload'])]
class PaymentCharge extends Model
{
    protected function casts(): array
    {
        return ['gateway_payload' => 'array', 'amount' => 'integer'];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }
}
