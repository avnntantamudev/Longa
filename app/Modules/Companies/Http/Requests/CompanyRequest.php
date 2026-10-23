<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Requests;

use App\Modules\Auth\Rules\AngolanPhone;
use App\Modules\Companies\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->route('company');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            /** @example Cantina Central Talatona */
            'name' => [$required, 'string', 'min:2', 'max:160'],
            /** NIF da empresa. @example 5001234567 */
            'nif' => ['nullable', 'string', 'regex:/^\d{9,14}$/', Rule::unique('companies', 'nif')->ignore($company instanceof Company ? $company->id : null)],
            'sector' => ['nullable', 'string', 'max:80'],
            'municipality' => ['nullable', 'string', 'max:80'],
            'neighborhood' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', new AngolanPhone],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nif.regex' => 'O NIF deve ter entre 9 e 14 dígitos.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'sector' => 'setor', 'municipality' => 'município', 'neighborhood' => 'bairro', 'address' => 'morada', 'phone' => 'telefone', 'email' => 'e-mail'];
    }
}
