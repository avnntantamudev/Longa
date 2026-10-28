<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nó da árvore de categorias, com os filhos (recursivo).
 *
 * @mixin Category
 */
class CategoryNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'description' => $this->description,
            'pricing_mode' => $this->pricing_mode->value,
            'children' => self::collection($this->children),
        ];
    }
}
