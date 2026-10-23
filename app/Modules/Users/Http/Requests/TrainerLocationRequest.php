<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Rules\InAngola;
use Illuminate\Foundation\Http\FormRequest;

class TrainerLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'municipality' => ['required', 'string', 'max:80'],
            'neighborhood' => ['required', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', new InAngola],
            'longitude' => ['required', 'numeric'],
            /** Raio de atuação em metros (1 a 20 km). @example 5000 */
            'service_radius_m' => ['required', 'integer', 'min:1000', 'max:20000'],
            'accept_location' => ['accepted'],
        ];
    }

    /**
     * @return array{municipality: string, neighborhood: string, latitude: float, longitude: float, service_radius_m: int}
     */
    public function locationData(): array
    {
        return [
            'municipality' => (string) $this->validated('municipality'),
            'neighborhood' => (string) $this->validated('neighborhood'),
            'latitude' => (float) $this->validated('latitude'),
            'longitude' => (float) $this->validated('longitude'),
            'service_radius_m' => (int) $this->validated('service_radius_m'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['municipality' => 'município', 'neighborhood' => 'bairro', 'service_radius_m' => 'raio de atuação', 'accept_location' => 'consentimento de localização'];
    }
}
