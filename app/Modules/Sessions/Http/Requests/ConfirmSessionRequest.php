<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Requests;

use App\Modules\Users\Rules\InAngola;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Conteúdo do QR lido. */
            'token' => ['required_without:code', 'nullable', 'string', 'max:64'],
            /** Código de 6 dígitos (alternativa ao QR). @example 482913 */
            'code' => ['required_without:token', 'nullable', 'string', 'digits:6'],
            /** Localização do cliente no momento (opcional; só sinal de risco). */
            'latitude' => ['nullable', 'numeric', 'required_with:longitude', new InAngola],
            'longitude' => ['nullable', 'numeric', 'required_with:latitude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['token' => 'QR', 'code' => 'código'];
    }
}
