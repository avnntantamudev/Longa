<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\ProviderProfile;
use App\Support\Settings\PlatformSettings;

/**
 * Comissão do BISCA, descontada ao técnico (decisão do cliente, docs/decisions.md).
 * Tudo em Kz inteiros; a comissão arredonda ao Kz, meio para cima.
 *
 * Ex.: 25.000 Kz com 15% → comissão 3.750 Kz, técnico recebe 21.250 Kz.
 */
final class Commission
{
    /**
     * @return array{amount: int, commission: int, provider_net: int}
     */
    public static function split(int $amount, ?int $percent = null): array
    {
        $percent ??= app(PlatformSettings::class)->int('commission_percent');
        $commission = intdiv($amount * $percent + 50, 100);

        return ['amount' => $amount, 'commission' => $commission, 'provider_net' => $amount - $commission];
    }

    /**
     * Percentagem a aplicar a este técnico agora: reduzida enquanto for VIP.
     */
    public static function percentFor(ProviderProfile|int $provider): int
    {
        $settings = app(PlatformSettings::class);

        return app(VipSubscriptions::class)->isVip($provider)
            ? $settings->int('vip_commission_percent')
            : $settings->int('commission_percent');
    }

    /**
     * @return array{amount: int, commission: int, provider_net: int, percent: int}
     */
    public static function splitFor(int $amount, ProviderProfile|int $provider): array
    {
        $percent = self::percentFor($provider);

        return [...self::split($amount, $percent), 'percent' => $percent];
    }
}
