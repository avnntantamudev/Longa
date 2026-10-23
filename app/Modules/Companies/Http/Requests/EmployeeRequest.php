<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Requests;

use App\Modules\Auth\Rules\AngolanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            /** @example João Pedro Martins */
            'name' => [$required, 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'string', new AngolanPhone],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            /** @example Operações */
            'department' => ['nullable', 'string', 'max:80'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'phone' => 'telefone', 'email' => 'e-mail', 'department' => 'departamento', 'job_title' => 'função', 'status' => 'estado'];
    }
}
