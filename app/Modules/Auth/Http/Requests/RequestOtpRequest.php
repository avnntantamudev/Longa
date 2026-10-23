<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Enums\OtpChannel;
use App\Modules\Auth\Rules\AngolanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestOtpRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Telemóvel angolano, em qualquer formato comum. @example 923 456 789 */
            'phone' => ['required', 'string', 'max:30', new AngolanPhone],
            /** Onde receber o código: "sms" (por defeito) ou "whatsapp". */
            'channel' => ['nullable', Rule::enum(OtpChannel::class)],
        ];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::tryFrom((string) $this->validated('channel')) ?? OtpChannel::Sms;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'telefone'];
    }
}
