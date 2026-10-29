<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pacote de aulas (ou sessões) de uma categoria por hora/pacote: N aulas com desconto. As subcategorias herdam os
 * pacotes da categoria-pai mais próxima que os tenha.
 *
 * @property int $id
 * @property int $category_id
 * @property int $sessions
 * @property int $discount_percent
 * @property bool $active
 * @property int $sort_order
 * @property-read Category $category
 */
#[Fillable(['category_id', 'sessions', 'discount_percent', 'active', 'sort_order'])]
class CategoryPackage extends Model
{
    protected function casts(): array
    {
        return ['sessions' => 'integer', 'discount_percent' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function label(): string
    {
        return "{$this->sessions} aulas".($this->discount_percent > 0 ? " (−{$this->discount_percent}%)" : '');
    }
}
