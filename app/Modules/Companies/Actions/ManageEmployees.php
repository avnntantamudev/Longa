<?php

declare(strict_types=1);

namespace App\Modules\Companies\Actions;

use App\Modules\Auth\Support\PhoneNumber;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;

/**
 * Funcionários de uma empresa geridos pelo RH. Se o telefone corresponder a um utilizador LONGA,
 * o funcionário fica ligado a essa conta (para presenças e certificados nas fases seguintes).
 */
class ManageEmployees
{
    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, department?: string|null, job_title?: string|null}  $data
     */
    public function add(Company $company, array $data): CompanyEmployee
    {
        $data = $this->normalize($data);
        $this->ensurePhoneIsFree($company, $data['phone'] ?? null);

        /** @var CompanyEmployee */
        return $company->employees()->create($data);
    }

    /**
     * @param  array{name?: string, phone?: string|null, email?: string|null, department?: string|null, job_title?: string|null, status?: string}  $data
     */
    public function update(CompanyEmployee $employee, array $data): CompanyEmployee
    {
        $data = $this->normalize($data);
        if (array_key_exists('phone', $data)) {
            $this->ensurePhoneIsFree($employee->company, $data['phone'], $employee->id);
        }

        $employee->fill($data)->save();

        return $employee;
    }

    public function remove(CompanyEmployee $employee): void
    {
        $employee->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone'] = filled($data['phone']) ? PhoneNumber::normalize((string) $data['phone']) : null;
            $data['user_id'] = $data['phone'] ? User::query()->where('phone', $data['phone'])->value('id') : null;
        }

        return $data;
    }

    private function ensurePhoneIsFree(Company $company, ?string $phone, ?int $ignoreId = null): void
    {
        if ($phone === null) {
            return;
        }

        $taken = $company->employees()->where('phone', $phone)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($taken) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['phone' => ['Já existe um funcionário com este telefone.']]);
        }
    }
}
