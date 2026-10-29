<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto ou vídeo enviado no formulário do pedido (disco privado; só se abre por link temporário).
 *
 * @property int $id
 * @property int $service_request_id
 * @property string $field_key
 * @property string $kind photo | video
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ServiceRequest $request
 */
#[Table('service_request_media')]
#[Fillable(['service_request_id', 'field_key', 'kind', 'path', 'original_name', 'mime', 'size'])]
class ServiceRequestMedia extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }
}
