<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Requests;

use App\Modules\Sessions\Rules\PdfSignature;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class SendMessageRequest extends FormRequest
{
    /**
     * Fotos até 5 MB (os PDF até ao limite geral do chat).
     */
    private function photoLimit(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && str_starts_with((string) $value->getMimeType(), 'image/') && $value->getSize() > (int) config('longa.chat.max_photo_kb') * 1024) {
            $fail('As fotos podem ter até '.intdiv((int) config('longa.chat.max_photo_kb'), 1024).' MB.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:2000'],
            /** PDF até 10 MB ou foto (JPG, PNG, WebP) até 5 MB; o tipo é verificado pelo conteúdo do ficheiro. */
            'attachment' => ['required_without:body', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:'.config('longa.chat.max_attachment_kb'), $this->photoLimit(...), new PdfSignature],
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
