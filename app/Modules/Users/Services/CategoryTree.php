<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\Category;
use Illuminate\Database\Eloquent\Collection;

/**
 * Árvore de categorias ativas carregada de uma vez (com campos e preços), com pais e filhos ligados em memória:
 * caminhos, herança e formulários sem uma consulta por nó.
 */
class CategoryTree
{
    /** @var Collection<int, Category>|null */
    private ?Collection $nodes = null;

    /**
     * @return Collection<int, Category> por id, na ordem da árvore
     */
    public function nodes(): Collection
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }

        $all = Category::query()->active()->with(['fields', 'price'])->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        foreach ($all as $node) {
            $node->setRelation('parent', $node->parent_id === null ? null : $all->get($node->parent_id));
            $node->setRelation('children', $all->where('parent_id', $node->id)->values());
        }

        return $this->nodes = $all;
    }

    /**
     * Folhas ativas (o que se pede e o que o técnico escolhe), na ordem da árvore. Uma categoria cujo pai está
     * inativo fica de fora.
     *
     * @return Collection<int, Category>
     */
    public function leaves(): Collection
    {
        return $this->nodes()
            ->filter(fn (Category $c) => $c->children->isEmpty() && $this->reachable($c))
            // Ordem da árvore: "00001.00003.00007" (comparar arrays em PHP põe primeiro os mais curtos)
            ->sortBy(fn (Category $c) => implode('.', array_map(fn (Category $a) => sprintf('%05d', $a->sort_order), [...$c->ancestors(), $c])))
            ->values();
    }

    /**
     * @return Collection<int, Category> raízes ativas, com os filhos já ligados
     */
    public function roots(): Collection
    {
        return $this->nodes()->whereNull('parent_id')->values();
    }

    public function find(int $id): ?Category
    {
        return $this->nodes()->get($id);
    }

    /** Todos os antepassados estão ativos (e carregados). */
    private function reachable(Category $category): bool
    {
        return $category->parent_id === null || $category->parent !== null && $this->reachable($category->parent);
    }
}
