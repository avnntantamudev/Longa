<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $nif
 * @property string|null $sector
 * @property string|null $municipality
 * @property string|null $neighborhood
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string $status
 * @property int|null $created_by
 */
#[Fillable(['name', 'nif', 'sector', 'municipality', 'neighborhood', 'address', 'phone', 'email', 'created_by'])]
class Company extends Model
{
    use SoftDeletes;

    protected $attributes = ['status' => 'active'];

    /**
     * Gestores (RH) da empresa.
     *
     * @return BelongsToMany<User, $this>
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<CompanyEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(CompanyEmployee::class);
    }

    public function isManagedBy(User $user): bool
    {
        return $this->managers()->whereKey($user->id)->exists();
    }
}
