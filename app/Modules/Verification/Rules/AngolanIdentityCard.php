<?php

declare(strict_types=1);

namespace App\Modules\Verification\Rules;

use App\Modules\Verification\Models\Verification;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nº do Bilhete de Identidade angolano: 9 dígitos, 2 letras (província) e 3 dígitos. Ex.: 001234567LA042.
 */
class AngolanIdentityCard implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{9}[A-Z]{2}\d{3}$/', Verification::normalizeDocument($value)) !== 1) {
            $fail('Indique um nº de BI válido (ex.: 001234567LA042).');
        }
    }
}
