<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Requests;

use App\Modules\Sessions\Models\Dispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class OpenDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @example lesson_not_happened */
            'reason' => ['required', Rule::in(Dispute::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:'.config('longa.disputes.max_attachments')],
            /** Imagem (JPG, PNG, WebP) ou PDF até 5 MB; o tipo é verificado pelo conteúdo. */
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:'.config('longa.disputes.max_attachment_kb'),
            ],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        $files = $this->file('attachments');

        return $files instanceof UploadedFile ? [$files] : array_values($files ?? []);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.max' => 'Pode anexar no máximo '.config('longa.disputes.max_attachments').' ficheiros.',
            'attachments.*.mimes' => 'Só são aceites imagens (JPG, PNG, WebP) ou PDF.',
            'attachments.*.mimetypes' => 'Só são aceites imagens (JPG, PNG, WebP) ou PDF.',
            'attachments.*.max' => 'Cada ficheiro pode ter no máximo '.(int) (config('longa.disputes.max_attachment_kb') / 1024).' MB.',
            'description.min' => 'Descreva o que aconteceu (pelo menos 10 caracteres).',
        ];
    }
}
