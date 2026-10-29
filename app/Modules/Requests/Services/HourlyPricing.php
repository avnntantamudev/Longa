<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

/**
 * Total dos serviços por hora/pacote: preço por hora × horas por aula × número de aulas, menos o desconto do pacote
 * (Kz inteiros, arredondado ao kwanza).
 */
final class HourlyPricing
{
    public static function total(int $hourlyRate, int $durationMinutes, int $sessions = 1, int $discountPercent = 0): int
    {
        return intdiv($hourlyRate * $durationMinutes * $sessions * (100 - $discountPercent) + 3000, 6000);
    }
}
