<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
            /** Código de 6 dígitos recebido por SMS. @example 123456 */
            'code' => ['required', 'string', 'digits:'.config('longa.otp.length')],
            /** Nome do dispositivo (só API). @example Samsung A14 */
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'telefone', 'code' => 'código', 'device_name' => 'dispositivo'];
    }
}
