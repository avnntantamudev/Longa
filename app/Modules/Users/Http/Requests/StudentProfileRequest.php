<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'municipality' => ['nullable', 'string', 'max:80'],
            'neighborhood' => ['nullable', 'string', 'max:80'],
            'education_level' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['municipality' => 'município', 'neighborhood' => 'bairro', 'education_level' => 'nível de escolaridade'];
    }
}
