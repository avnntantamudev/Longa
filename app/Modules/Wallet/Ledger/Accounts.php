<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Ledger;

use App\Modules\Users\Models\ProviderProfile;

/**
 * Códigos e tipos das contas do livro-razão (docs/ledger.md).
 *
 * Convenção de sinal (a soma de todas as contas é sempre 0):
 *  - funds_in              contrapartida do dinheiro que entra pelo gateway (negativa quando entra dinheiro)
 *  - escrow                dinheiro dos clientes retido (positiva; = soma dos holds "held")
 *  - platform_revenue      comissões do BISCA (positiva)
 *  - refunds_payable       reembolsos decididos à espera do gateway (positiva enquanto por pagar)
 *  - cash_settlement       contrapartida das comissões recebidas em dinheiro (negativa quando recebe)
 *  - provider_available:{id}       saldo disponível do técnico (positivo)
 *  - provider_commission_due:{id}  comissão em dívida de serviços pagos em dinheiro (negativa = deve)
 */
final class Accounts
{
    public const FUNDS_IN = 'funds_in';

    public const ESCROW = 'escrow';

    public const PLATFORM_REVENUE = 'platform_revenue';

    public const REFUNDS_PAYABLE = 'refunds_payable';

    public const CASH_SETTLEMENT = 'cash_settlement';

    /** Receita das subscrições VIP (separada das comissões) */
    public const SUBSCRIPTION_REVENUE = 'subscription_revenue';

    /** Levantamentos pedidos pelos técnicos e ainda por pagar */
    public const WITHDRAWALS_PENDING = 'withdrawals_pending';

    /** Dinheiro que saiu da plataforma para os técnicos (levantamentos pagos) */
    public const PAYOUTS = 'payouts';

    /** Contrapartida dos ajustes feitos pela equipa às carteiras */
    public const ADJUSTMENTS = 'adjustments';

    public const PROVIDER_AVAILABLE = 'provider_available';

    public const PROVIDER_COMMISSION_DUE = 'provider_commission_due';

    /** @var list<string> */
    public const SYSTEM = [
        self::FUNDS_IN, self::ESCROW, self::PLATFORM_REVENUE, self::REFUNDS_PAYABLE, self::CASH_SETTLEMENT, self::SUBSCRIPTION_REVENUE,
        self::WITHDRAWALS_PENDING, self::PAYOUTS, self::ADJUSTMENTS,
    ];

    public static function providerAvailable(ProviderProfile|int $provider): string
    {
        return self::PROVIDER_AVAILABLE.':'.self::id($provider);
    }

    public static function providerCommissionDue(ProviderProfile|int $provider): string
    {
        return self::PROVIDER_COMMISSION_DUE.':'.self::id($provider);
    }

    /**
     * Tipo e dono a partir do código ("provider_available:12" → [provider_available, 12]).
     *
     * @return array{0: string, 1: int|null}
     */
    public static function parse(string $code): array
    {
        [$type, $owner] = array_pad(explode(':', $code, 2), 2, null);

        return [$type, $owner === null ? null : (int) $owner];
    }

    private static function id(ProviderProfile|int $provider): int
    {
        return $provider instanceof ProviderProfile ? $provider->id : $provider;
    }
}
