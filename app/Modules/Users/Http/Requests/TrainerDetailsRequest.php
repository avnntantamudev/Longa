<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainerDetailsRequest extends FormRequest
{
    public const RATE_UNITS = ['hour', 'two_hours', 'day', 'session'];

    public const MODALITIES = ['in_person', 'online'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Apresentação do formador. */
            'bio' => ['required', 'string', 'min:20', 'max:2000'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'modalities' => ['required', 'array', 'min:1'],
            'modalities.*' => ['string', 'distinct', Rule::in(self::MODALITIES)],
            /** Preço de referência em Kz inteiros. @example 5000 */
            'rate_amount' => ['nullable', 'integer', 'min:500', 'max:10000000'],
            'rate_unit' => ['nullable', 'required_with:rate_amount', Rule::in(self::RATE_UNITS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['bio' => 'apresentação', 'years_experience' => 'anos de experiência', 'modalities' => 'modalidades', 'rate_amount' => 'preço', 'rate_unit' => 'unidade do preço'];
    }
}
