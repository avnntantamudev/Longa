<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use App\Modules\Requests\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chat do serviço (cliente ↔ técnico).
 *
 * @property int $id
 * @property int $service_request_id
 * @property-read ServiceRequest $session
 */
#[Fillable(['service_request_id'])]
class Conversation extends Model
{
    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
