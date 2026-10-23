<?php

declare(strict_types=1);

namespace App\Modules\Auth\Rules;

use App\Modules\Auth\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AngolanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::parse($value) === null) {
            $fail('Indique um número de telemóvel angolano válido (ex.: 923 456 789).');
        }
    }
}
