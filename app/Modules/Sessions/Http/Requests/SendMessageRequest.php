<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Requests;

use App\Modules\Sessions\Rules\PdfSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:2000'],
            /** PDF até 10 MB; o tipo é verificado pelo conteúdo do ficheiro. */
            'attachment' => ['required_without:body', 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.config('longa.chat.max_attachment_kb'), new PdfSignature],
            /** Id gerado no cliente para reenvios sem duplicar. @example 3f1c9a2e-... */
            'client_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function attachment(): ?UploadedFile
    {
        $file = $this->file('attachment');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachment.mimes' => 'Só são aceites ficheiros PDF.',
            'attachment.mimetypes' => 'Só são aceites ficheiros PDF.',
            'attachment.max' => 'O ficheiro não pode ter mais de '.(int) (config('longa.chat.max_attachment_kb') / 1024).' MB.',
            'body.required_without' => 'Escreva uma mensagem ou anexe um PDF.',
        ];
    }
}
