<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Carbon;

/**
 * Formatos curtos em português (Angola) para notificações.
 */
final class Formats
{
    /**
     * 25000 → "25.000 Kz"
     */
    public static function kwanza(int $amount): string
    {
        return number_format($amount, 0, ',', '.').' Kz';
    }

    /**
     * 1234 → "1,2 km"; 800 → "800 m"
     */
    public static function distance(int $meters): string
    {
        if ($meters < 1000) {
            return (string) (int) (round($meters / 100) * 100).' m';
        }

        return number_format($meters / 1000, 1, ',', '').' km';
    }

    /**
     * "hoje 14:00", "amanhã 09:30", "sáb 12/10 14:00"
     */
    public static function when(Carbon $at): string
    {
        $at = $at->copy()->setTimezone(config('app.timezone'));
        $time = $at->format('H:i');

        if ($at->isToday()) {
            return "hoje {$time}";
        }

        if ($at->isTomorrow()) {
            return "amanhã {$time}";
        }

        $days = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

        return $days[$at->dayOfWeek].' '.$at->format('d/m').' '.$time;
    }
}
