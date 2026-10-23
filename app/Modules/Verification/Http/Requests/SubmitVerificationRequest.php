<?php

declare(strict_types=1);

namespace App\Modules\Verification\Http\Requests;

use App\Modules\Verification\Rules\AngolanIdentityCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class SubmitVerificationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Nº do BI. @example 001234567LA042 */
            'document_number' => ['required', 'string', 'max:20', new AngolanIdentityCard],
            /** Frente e verso do BI (JPG, PNG ou PDF, até 5 MB cada). */
            'files' => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function documentFiles(): array
    {
        return array_values(array_filter((array) $this->file('files'), fn ($f) => $f instanceof UploadedFile));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['document_number' => 'nº do BI', 'files' => 'documentos'];
    }
}
