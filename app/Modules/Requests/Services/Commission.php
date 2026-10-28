<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\TrainerProfile;
use App\Support\Settings\PlatformSettings;

/**
 * Comissão do BISCA, descontada ao formador (decisão do cliente, docs/decisions.md).
 * Tudo em Kz inteiros; a comissão arredonda ao Kz, meio para cima.
 *
 * Ex.: 25.000 Kz com 15% → comissão 3.750 Kz, formador recebe 21.250 Kz.
 */
final class Commission
{
    /**
     * @return array{amount: int, commission: int, trainer_net: int}
     */
    public static function split(int $amount, ?int $percent = null): array
    {
        $percent ??= app(PlatformSettings::class)->int('commission_percent');
        $commission = intdiv($amount * $percent + 50, 100);

        return ['amount' => $amount, 'commission' => $commission, 'trainer_net' => $amount - $commission];
    }

    /**
     * Percentagem a aplicar a este formador agora: reduzida enquanto for VIP.
     */
    public static function percentFor(TrainerProfile|int $trainer): int
    {
        $settings = app(PlatformSettings::class);

        return app(VipSubscriptions::class)->isVip($trainer)
            ? $settings->int('vip_commission_percent')
            : $settings->int('commission_percent');
    }

    /**
     * @return array{amount: int, commission: int, trainer_net: int, percent: int}
     */
    public static function splitFor(int $amount, TrainerProfile|int $trainer): array
    {
        $percent = self::percentFor($trainer);

        return [...self::split($amount, $percent), 'percent' => $percent];
    }
}
