<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\PricingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Categoria de serviço numa árvore (ex.: Casa › Climatização › Manutenção de ar condicionado).
 * Os pedidos e os técnicos ligam-se às folhas. O modo de preço é de cada categoria (uma subcategoria nova
 * começa com o do pai); as modalidades e os preços vêm do antepassado mais próximo que os defina; os documentos
 * exigidos e o formulário juntam os de toda a linha.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $area
 * @property PricingMode $pricing_mode
 * @property list<string>|null $modalities
 * @property list<string>|null $required_documents
 * @property int $sort_order
 * @property bool $active
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, CategoryField> $fields
 * @property-read CategoryPrice|null $price
 */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'icon', 'area', 'pricing_mode', 'modalities', 'required_documents', 'sort_order', 'active'])]
class Category extends Model
{
    /** Profundidade máxima da árvore (proteção contra ciclos nas subidas). */
    public const MAX_DEPTH = 6;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'pricing_mode' => PricingMode::class,
            'modalities' => 'array',
            'required_documents' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<CategoryField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(CategoryField::class)->orderBy('sort_order');
    }

    /**
     * @return HasOne<CategoryPrice, $this>
     */
    public function price(): HasOne
    {
        return $this->hasOne(CategoryPrice::class);
    }

    /**
     * Pacotes de aulas (modo hora/pacote).
     *
     * @return HasMany<CategoryPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(CategoryPackage::class)->orderBy('sort_order')->orderBy('sessions');
    }

    /**
     * @return BelongsToMany<ProviderProfile, $this>
     */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(ProviderProfile::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Folhas: categorias sem subcategorias ativas (as únicas que se pedem e que os técnicos escolhem).
     *
     * @param  Builder<self>  $query
     */
    public function scopeLeaves(Builder $query): void
    {
        $query->whereDoesntHave('children', fn (Builder $q) => $q->where('active', true));
    }

    /**
     * Antepassados, da raiz até ao pai (sem a própria categoria).
     *
     * @return list<self>
     */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this->parent;
        while ($node !== null && count($chain) < self::MAX_DEPTH) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $chain;
    }

    /** "Casa › Climatização › Manutenção de ar condicionado" */
    public function path(): string
    {
        return implode(' › ', [...array_map(fn (self $c) => $c->name, $this->ancestors()), $this->name]);
    }

    /**
     * Formulário completo: campos dos antepassados primeiro, depois os próprios. Um campo com a mesma chave
     * numa subcategoria substitui o herdado.
     *
     * @return list<CategoryField>
     */
    public function formFields(): array
    {
        $byKey = [];
        foreach ([...$this->ancestors(), $this] as $node) {
            foreach ($node->fields as $field) {
                $byKey[$field->key] = $field;
            }
        }

        return array_values($byKey);
    }

    /**
     * Modalidades permitidas (as próprias ou as do antepassado mais próximo; por omissão só presencial).
     *
     * @return list<string>
     */
    public function effectiveModalities(): array
    {
        foreach ([$this, ...array_reverse($this->ancestors())] as $node) {
            if (! empty($node->modalities)) {
                return $node->modalities;
            }
        }

        return ['in_person'];
    }

    /**
     * Documentos exigidos ao técnico: soma dos da categoria e dos antepassados.
     *
     * @return list<string>
     */
    public function effectiveRequiredDocuments(): array
    {
        $docs = [];
        foreach ([...$this->ancestors(), $this] as $node) {
            $docs = [...$docs, ...($node->required_documents ?? [])];
        }

        return array_values(array_unique($docs));
    }

    /**
     * Pacotes ativos da própria categoria ou do antepassado mais próximo que os tenha.
     *
     * @return list<CategoryPackage>
     */
    public function effectivePackages(): array
    {
        foreach ([$this, ...array_reverse($this->ancestors())] as $node) {
            $active = $node->packages->where('active', true)->values()->all();
            if ($active !== []) {
                return $active;
            }
        }

        return [];
    }

    /** Preços da própria categoria ou do antepassado mais próximo que os tenha. */
    public function effectivePrice(): ?CategoryPrice
    {
        foreach ([$this, ...array_reverse($this->ancestors())] as $node) {
            if ($node->price !== null) {
                return $node->price;
            }
        }

        return null;
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /** Impede que a categoria fique pendurada em si própria ou num descendente (ciclo na árvore). */
    public function canHaveParent(?self $parent): bool
    {
        for ($node = $parent, $depth = 0; $node !== null && $depth <= self::MAX_DEPTH; $node = $node->parent, $depth++) {
            if ($node->is($this)) {
                return false;
            }
        }

        return $parent === null || count($parent->ancestors()) + 1 < self::MAX_DEPTH;
    }
}
