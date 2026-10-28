<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preços de referência de uma categoria, em Kz inteiros: fixo, intervalo para alertas de orçamentos fora do
 * normal, limites por hora (aulas) e taxa de visita de diagnóstico.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $fixed_price
 * @property int|null $reference_min
 * @property int|null $reference_max
 * @property int|null $hourly_min
 * @property int|null $hourly_max
 * @property int|null $visit_fee
 * @property int|null $updated_by
 */
#[Fillable(['category_id', 'fixed_price', 'reference_min', 'reference_max', 'hourly_min', 'hourly_max', 'visit_fee', 'updated_by'])]
class CategoryPrice extends Model
{
    protected function casts(): array
    {
        return [
            'fixed_price' => 'integer', 'reference_min' => 'integer', 'reference_max' => 'integer',
            'hourly_min' => 'integer', 'hourly_max' => 'integer', 'visit_fee' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** O valor está fora do intervalo de referência (para alertas no painel); sem intervalo nunca está. */
    public function isOutsideReference(int $amount): bool
    {
        return ($this->reference_min !== null && $amount < $this->reference_min)
            || ($this->reference_max !== null && $amount > $this->reference_max);
    }
}
