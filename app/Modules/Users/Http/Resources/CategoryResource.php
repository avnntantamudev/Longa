<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Categoria escolhível (folha), com o caminho na árvore. Os pais vêm já ligados (CategoryTree).
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ancestors = $this->ancestors();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            /** Caminho completo, ex.: "Casa › Climatização › Manutenção de ar condicionado" */
            'path' => $this->path(),
            /** Caminho do pai, para agrupar listas (null nas raízes) */
            'group' => $ancestors === [] ? null : implode(' › ', array_map(fn (Category $c) => $c->name, $ancestors)),
            'pricing_mode' => $this->pricing_mode->value,
            /** @var list<string> */
            'modalities' => $this->effectiveModalities(),
        ];
    }
}
