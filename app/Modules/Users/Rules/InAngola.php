<?php

declare(strict_types=1);

namespace App\Modules\Users\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que o par latitude/longitude cai dentro de Angola (retângulo aproximado, config/locations.php).
 * Aplicar ao campo "latitude"; a longitude é lida dos restantes dados.
 */
class InAngola implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $longitudeField = 'longitude') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $lng = data_get($this->data, $this->longitudeField);
        $b = config('locations.bounds');

        if (! is_numeric($value) || ! is_numeric($lng)
            || $value < $b['lat_min'] || $value > $b['lat_max']
            || $lng < $b['lng_min'] || $lng > $b['lng_max']) {
            $fail('O local indicado tem de ficar em Angola.');
        }
    }
}
