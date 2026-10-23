<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'email' => 'e-mail'];
    }
}
