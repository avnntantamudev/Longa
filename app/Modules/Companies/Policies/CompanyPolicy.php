<?php

declare(strict_types=1);

namespace App\Modules\Companies\Policies;

use App\Modules\Companies\Models\Company;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $this->manage($user, $company);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasRole(Role::Admin->value) || $company->isManagedBy($user);
    }
}
