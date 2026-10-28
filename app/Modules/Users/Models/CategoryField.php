<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\FieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Campo do formulário de uma categoria (ex.: "Disciplina", "Fotos do aparelho"). As respostas ficam no pedido,
 * com a chave `key`.
 *
 * @property int $id
 * @property int $category_id
 * @property string $key
 * @property string $label
 * @property FieldType $type
 * @property bool $required
 * @property list<string>|null $options
 * @property string|null $help
 * @property int $sort_order
 */
#[Fillable(['category_id', 'key', 'label', 'type', 'required', 'options', 'help', 'sort_order'])]
class CategoryField extends Model
{
    protected function casts(): array
    {
        return ['type' => FieldType::class, 'required' => 'boolean', 'options' => 'array', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
