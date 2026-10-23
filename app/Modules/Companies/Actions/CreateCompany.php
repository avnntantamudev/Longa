<?php

declare(strict_types=1);

namespace App\Modules\Companies\Actions;

use App\Modules\Companies\Models\Company;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Cria a empresa e torna o utilizador o seu gestor principal (perfil Empresa/RH).
 */
class CreateCompany
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, nif?: string|null, sector?: string|null, municipality?: string|null, neighborhood?: string|null, address?: string|null, phone?: string|null, email?: string|null}  $data
     */
    public function handle(User $user, array $data): Company
    {
        return DB::transaction(function () use ($user, $data) {
            $company = Company::query()->create([...$data, 'created_by' => $user->id]);
            $company->managers()->attach($user->id, ['role' => 'owner']);
            $user->assignRole(Role::CompanyRh->value);

            $this->audit->record('companies.created', $company, [], $user->id);

            return $company;
        });
    }
}
