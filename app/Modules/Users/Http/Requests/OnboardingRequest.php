<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @example Maria Silva */
            'name' => ['required', 'string', 'min:2', 'max:120'],
            /** Um ou mais perfis. @example ["client"] */
            'profiles' => ['required', 'array', 'min:1', 'max:3'],
            'profiles.*' => ['string', 'distinct', Rule::in(array_map(fn (Role $r) => $r->value, Role::selfAssignable()))],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ];
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return array_map(fn (string $value) => Role::from($value), (array) $this->validated('profiles'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'profiles' => 'perfis', 'accept_terms' => 'termos de utilização', 'accept_privacy' => 'política de privacidade'];
    }
}
