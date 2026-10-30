<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Models;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de um pedido pago: o cliente avalia o técnico e o técnico avalia o cliente (uma de cada por pedido).
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $rater_id
 * @property int $ratee_id
 * @property string $direction
 * @property int $stars
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ServiceRequest $request
 * @property-read User $rater
 */
#[Fillable(['service_request_id', 'rater_id', 'ratee_id', 'direction', 'stars', 'comment'])]
class Rating extends Model
{
    public const UPDATED_AT = null;

    public const CLIENT_TO_PROVIDER = 'client_to_provider';

    public const PROVIDER_TO_CLIENT = 'provider_to_client';

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }
}
