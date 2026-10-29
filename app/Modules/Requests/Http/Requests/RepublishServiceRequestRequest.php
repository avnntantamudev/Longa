<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RepublishServiceRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lead = (int) config('longa.requests.min_lead_minutes');

        return [
            /** Nova data (opcional). */
            'scheduled_at' => ['nullable', 'date', 'after:'.now()->addMinutes($lead)->toDateTimeString()],
            /** Hora/pacote: novo preço máximo por hora em Kz (opcional). */
            'max_hourly_rate' => ['nullable', 'integer', 'min:500', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['scheduled_at.after' => 'Escolha uma data com pelo menos '.config('longa.requests.min_lead_minutes').' minutos de antecedência.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['scheduled_at' => 'data e hora', 'max_hourly_rate' => 'preço máximo por hora'];
    }
}
