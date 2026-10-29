<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Users\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orçamento de um técnico para um pedido por orçamento: mão de obra, materiais, prazo e validade. Uma revisão
 * substitui o orçamento anterior (superseded). Ao ser aceite, o preço do pedido fica bloqueado.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $provider_profile_id
 * @property int $revision
 * @property int $labour_amount
 * @property int $materials_amount
 * @property list<array{descricao: string, quantidade: int, valor: int}>|null $materials
 * @property int $total
 * @property int $visit_fee_deducted
 * @property int $execution_days
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $valid_until
 * @property string $status
 * @property bool $outside_reference
 * @property string|null $client_note
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ServiceRequest $request
 * @property-read ProviderProfile $provider
 */
#[Fillable([
    'service_request_id', 'provider_profile_id', 'revision', 'labour_amount', 'materials_amount', 'materials', 'total',
    'visit_fee_deducted', 'execution_days', 'notes', 'valid_until', 'outside_reference',
])]
class Quote extends Model
{
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const REFUSED = 'refused';

    public const REVISION_REQUESTED = 'revision_requested';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    protected $attributes = ['status' => self::SENT, 'revision' => 1, 'materials_amount' => 0, 'visit_fee_deducted' => 0];

    protected function casts(): array
    {
        return [
            'materials' => 'array',
            'valid_until' => 'datetime',
            'responded_at' => 'datetime',
            'outside_reference' => 'boolean',
            'labour_amount' => 'integer',
            'materials_amount' => 'integer',
            'total' => 'integer',
            'visit_fee_deducted' => 'integer',
            'execution_days' => 'integer',
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /** Valor a pagar pelo cliente: total menos a taxa de visita já paga em mão. */
    public function amountDue(): int
    {
        return max(0, $this->total - $this->visit_fee_deducted);
    }

    public function isOpen(): bool
    {
        return $this->status === self::SENT && $this->valid_until->isFuture();
    }
}
