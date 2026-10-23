<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainerSkillsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Áreas de formação (1 a 10). @example [1, 4] */
            'skill_ids' => ['required', 'array', 'min:1', 'max:10'],
            'skill_ids.*' => ['integer', 'distinct', Rule::exists('skills', 'id')->where('active', true)],
        ];
    }

    /**
     * @return list<int>
     */
    public function skillIds(): array
    {
        return array_map('intval', (array) $this->validated('skill_ids'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['skill_ids' => 'áreas de formação'];
    }
}
