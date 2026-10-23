<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Sessions\Models\TrainingSession;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido ao gateway (cobrança ou reembolso) e a respetiva resposta.
 *
 * @property int $id
 * @property int $training_session_id
 * @property int $student_id
 * @property string $provider
 * @property string $method
 * @property string $kind
 * @property int $amount
 * @property string $status
 * @property string|null $external_reference
 * @property string $idempotency_key
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $provider_payload
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['training_session_id', 'student_id', 'provider', 'method', 'kind', 'amount', 'status', 'external_reference', 'idempotency_key', 'failure_reason', 'provider_payload'])]
class PaymentCharge extends Model
{
    protected function casts(): array
    {
        return ['provider_payload' => 'array', 'amount' => 'integer'];
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }
}
