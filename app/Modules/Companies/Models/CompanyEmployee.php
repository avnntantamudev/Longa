<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Funcionário de uma empresa: pode estar ligado a um utilizador LONGA ou ser só um registo do RH.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $department
 * @property string|null $job_title
 * @property string $status
 */
#[Fillable(['user_id', 'name', 'phone', 'email', 'department', 'job_title', 'status'])]
class CompanyEmployee extends Model
{
    protected $attributes = ['status' => 'active'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
