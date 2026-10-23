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
 * @property string $category
 * @property bool $active
 */
#[Fillable(['name', 'slug', 'category', 'active'])]
class Skill extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<TrainerProfile, $this>
     */
    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(TrainerProfile::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
