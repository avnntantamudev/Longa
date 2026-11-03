<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Payments\Services\VipSubscriptions;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;

/**
 * Quem recebe pacotes e pedidos diretos, conforme as Definições (pode abrir-se a todos numa fase de lançamento e
 * reservar-se aos técnicos VIP depois, sem mudar código):
 *  - packages_access: 0 = todos os técnicos; 1 = VIP primeiro (durante a janela), depois todos; 2 = só VIP;
 *  - direct_requests_vip_only: 1 = só técnicos VIP aceitam pedidos diretos; 0 = qualquer técnico que já serviu o cliente.
 */
class VipFeatureAccess
{
    public const PACKAGES_EVERYONE = 0;

    public const PACKAGES_VIP_FIRST = 1;

    public const PACKAGES_VIP_ONLY = 2;

    public function __construct(private readonly PlatformSettings $settings, private readonly VipSubscriptions $vip) {}

    public function packagesAccess(): int
    {
        return $this->settings->int('packages_access');
    }

    /**
     * Até quando um pacote novo só vai a técnicos VIP (null = abre logo a todos).
     */
    public function packageVipUntil(Carbon $expiresAt): ?Carbon
    {
        return match ($this->packagesAccess()) {
            self::PACKAGES_EVERYONE => null,
            self::PACKAGES_VIP_ONLY => $expiresAt,
            default => now()->addMinutes($this->settings->int('packages_vip_window_minutes'))->min($expiresAt),
        };
    }

    /** Os pacotes são uma vantagem dos VIP (recebem-nos primeiro ou só eles). */
    public function packagesAreVipBenefit(): bool
    {
        return $this->packagesAccess() !== self::PACKAGES_EVERYONE;
    }

    public function directRequiresVip(): bool
    {
        return $this->settings->int('direct_requests_vip_only') === 1;
    }

    /** O técnico aceita pedidos diretos (é VIP, ou os pedidos diretos estão abertos a todos). */
    public function acceptsDirect(int $providerProfileId): bool
    {
        return ! $this->directRequiresVip() || $this->vip->isVip($providerProfileId);
    }
}
