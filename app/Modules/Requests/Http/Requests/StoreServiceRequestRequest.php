<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Requests;

use App\Modules\Users\Rules\InAngola;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequestRequest extends FormRequest
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
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            /** Tema da aula. @example Aula de SGME */
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            /** Valor oferecido em Kz inteiros. @example 25000 */
            'amount' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            /** Data e hora pretendidas (ISO 8601). @example 2026-10-24T14:00:00+01:00 */
            'scheduled_at' => ['required', 'date', 'after:'.now()->addMinutes($lead)->toDateTimeString()],
            /** Duração da aula em minutos (30 a 480). @example 60 */
            'duration_minutes' => ['sometimes', 'integer', 'min:30', 'max:480', 'multiple_of:15'],
            /** "electronic" (Multicaixa, retido em escrow) ou "cash" (pago em mão ao formador). */
            'payment_method' => ['sometimes', Rule::in(['electronic', 'cash'])],
            'modality' => ['required', Rule::in(['in_person', 'online'])],
            /** Província (lista de 21). Sem valor: Luanda. @example Luanda */
            'province' => ['sometimes', 'string', Rule::in(config('locations.provinces'))],
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
            'category_id' => 'área de formação', 'title' => 'tema', 'description' => 'descrição', 'amount' => 'valor',
            'scheduled_at' => 'data e hora', 'duration_minutes' => 'duração', 'payment_method' => 'forma de pagamento', 'modality' => 'modalidade', 'municipality' => 'município', 'neighborhood' => 'bairro',
        ];
    }

    /**
     * @return array{category_id: int, title: string, description: string|null, amount: int, scheduled_at: string, duration_minutes: int, payment_method: string, modality: string, province: string, municipality: string, neighborhood: string, latitude: float, longitude: float}
     */
    public function requestData(): array
    {
        return [
            'category_id' => (int) $this->validated('category_id'),
            'title' => (string) $this->validated('title'),
            'description' => $this->validated('description'),
            'amount' => (int) $this->validated('amount'),
            'scheduled_at' => (string) $this->validated('scheduled_at'),
            'duration_minutes' => (int) ($this->validated('duration_minutes') ?? 60),
            'payment_method' => (string) ($this->validated('payment_method') ?? 'electronic'),
            'modality' => (string) $this->validated('modality'),
            'province' => (string) ($this->validated('province') ?? 'Luanda'),
            'municipality' => (string) $this->validated('municipality'),
            'neighborhood' => (string) $this->validated('neighborhood'),
            'latitude' => (float) $this->validated('latitude'),
            'longitude' => (float) $this->validated('longitude'),
        ];
    }
}
