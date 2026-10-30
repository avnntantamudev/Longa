<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Confirma pelo conteúdo que o ficheiro é um PDF (assinatura "%PDF-" no início),
 * independentemente da extensão ou do tipo declarado pelo cliente.
 */
class PdfSignature implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Só os PDF (as fotos do chat são verificadas pelo tipo de conteúdo)
        if (! $value instanceof UploadedFile || str_starts_with((string) $value->getMimeType(), 'image/')) {
            return;
        }

        $handle = @fopen($value->getRealPath(), 'rb');
        $header = $handle ? (string) fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            $fail('Só são aceites ficheiros PDF.');
        }
    }
}
