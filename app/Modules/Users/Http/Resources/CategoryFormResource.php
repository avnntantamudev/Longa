<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategoryField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tudo o que o ecrã de pedido precisa para uma categoria: formulário (com os campos herdados), modalidades,
 * modo de preço e preços de referência (Kz).
 *
 * @mixin Category
 */
class CategoryFormResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->effectivePrice();

        return [
            'category' => new CategoryResource($this->resource),
            'fields' => array_map(fn (CategoryField $f) => [
                'key' => $f->key,
                'label' => $f->label,
                /** text | number | choice | photos | video */
                'type' => $f->type->value,
                'required' => $f->required,
                'options' => $f->options,
                'help' => $f->help,
            ], $this->formFields()),
            'price' => $price === null ? null : [
                'fixed_price' => $price->fixed_price,
                'reference_min' => $price->reference_min,
                'reference_max' => $price->reference_max,
                'hourly_min' => $price->hourly_min,
                'hourly_max' => $price->hourly_max,
                'visit_fee' => $price->visit_fee,
            ],
        ];
    }
}
