<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Ledger;

use App\Modules\Users\Models\TrainerProfile;

/**
 * Códigos e tipos das contas do livro-razão (docs/ledger.md).
 *
 * Convenção de sinal (a soma de todas as contas é sempre 0):
 *  - funds_in              contrapartida do dinheiro que entra pelo gateway (negativa quando entra dinheiro)
 *  - escrow                dinheiro dos alunos retido (positiva; = soma dos holds "held")
 *  - platform_revenue      comissões da LONGA (positiva)
 *  - refunds_payable       reembolsos decididos à espera do gateway (positiva enquanto por pagar)
 *  - cash_settlement       contrapartida das comissões recebidas em dinheiro (negativa quando recebe)
 *  - trainer_available:{id}       saldo disponível do formador (positivo)
 *  - trainer_commission_due:{id}  comissão em dívida de aulas pagas em dinheiro (negativa = deve)
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

    public const TRAINER_AVAILABLE = 'trainer_available';

    public const TRAINER_COMMISSION_DUE = 'trainer_commission_due';

    /** @var list<string> */
    public const SYSTEM = [self::FUNDS_IN, self::ESCROW, self::PLATFORM_REVENUE, self::REFUNDS_PAYABLE, self::CASH_SETTLEMENT, self::SUBSCRIPTION_REVENUE];

    public static function trainerAvailable(TrainerProfile|int $trainer): string
    {
        return self::TRAINER_AVAILABLE.':'.self::id($trainer);
    }

    public static function trainerCommissionDue(TrainerProfile|int $trainer): string
    {
        return self::TRAINER_COMMISSION_DUE.':'.self::id($trainer);
    }

    /**
     * Tipo e dono a partir do código ("trainer_available:12" → [trainer_available, 12]).
     *
     * @return array{0: string, 1: int|null}
     */
    public static function parse(string $code): array
    {
        [$type, $owner] = array_pad(explode(':', $code, 2), 2, null);

        return [$type, $owner === null ? null : (int) $owner];
    }

    private static function id(TrainerProfile|int $trainer): int
    {
        return $trainer instanceof TrainerProfile ? $trainer->id : $trainer;
    }
}
