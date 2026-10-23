<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Requests;

use App\Modules\Users\Rules\InAngola;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = (int) config('longa.requests.min_amount');
        $max = (int) config('longa.requests.max_amount');
        $lead = (int) config('longa.requests.min_lead_minutes');

        return [
            'skill_id' => ['required', 'integer', Rule::exists('skills', 'id')->where('active', true)],
            /** Tema da aula. @example Aula de SGME */
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            /** Valor oferecido em Kz inteiros. @example 25000 */
            'amount' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            /** Data e hora pretendidas (ISO 8601). @example 2026-10-24T14:00:00+01:00 */
            'scheduled_at' => ['required', 'date', 'after:'.now()->addMinutes($lead)->toDateTimeString()],
            'modality' => ['required', Rule::in(['in_person', 'online'])],
            'municipality' => ['required', 'string', 'max:80'],
            'neighborhood' => ['required', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', new InAngola],
            'longitude' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'A aula tem de ser marcada com pelo menos '.config('longa.requests.min_lead_minutes').' minutos de antecedência.',
            'amount.min' => 'O valor mínimo é :min Kz.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'skill_id' => 'área de formação', 'title' => 'tema', 'description' => 'descrição', 'amount' => 'valor',
            'scheduled_at' => 'data e hora', 'modality' => 'modalidade', 'municipality' => 'município', 'neighborhood' => 'bairro',
        ];
    }

    /**
     * @return array{skill_id: int, title: string, description: string|null, amount: int, scheduled_at: string, modality: string, municipality: string, neighborhood: string, latitude: float, longitude: float}
     */
    public function requestData(): array
    {
        return [
            'skill_id' => (int) $this->validated('skill_id'),
            'title' => (string) $this->validated('title'),
            'description' => $this->validated('description'),
            'amount' => (int) $this->validated('amount'),
            'scheduled_at' => (string) $this->validated('scheduled_at'),
            'modality' => (string) $this->validated('modality'),
            'municipality' => (string) $this->validated('municipality'),
            'neighborhood' => (string) $this->validated('neighborhood'),
            'latitude' => (float) $this->validated('latitude'),
            'longitude' => (float) $this->validated('longitude'),
        ];
    }
}
