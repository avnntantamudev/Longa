<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Área de formação (ex.: SGME, Primavera, Matemática).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $area
 * @property bool $active
 */
#[Fillable(['name', 'slug', 'area', 'active'])]
class Category extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
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
}
