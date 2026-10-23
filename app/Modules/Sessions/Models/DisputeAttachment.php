<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Evidência de uma disputa (imagem ou PDF) no disco privado; acesso por link assinado temporário.
 *
 * @property int $id
 * @property int $dispute_id
 * @property int $uploaded_by
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 */
#[Fillable(['dispute_id', 'uploaded_by', 'path', 'original_name', 'mime', 'size'])]
#[Hidden(['path'])]
class DisputeAttachment extends Model
{
    public const UPDATED_AT = null;
}
