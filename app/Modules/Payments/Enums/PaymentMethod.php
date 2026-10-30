<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Meios de pagamento aceites. Os digitais passam pelo gateway (hoje simulados, com a mesma interface que o
 * fornecedor real); o dinheiro é pago em mão ao técnico, sem cobrança eletrónica.
 */
enum PaymentMethod: string
{
    case MulticaixaExpress = 'multicaixa_express';
    case Reference = 'reference';
    case Card = 'card';
    case Cash = 'cash';

    /**
     * @return list<string>
     */
    public static function digitalValues(): array
    {
        return [self::MulticaixaExpress->value, self::Reference->value, self::Card->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::MulticaixaExpress => 'Multicaixa Express',
            self::Reference => 'Referência de pagamento',
            self::Card => 'Cartão',
            self::Cash => 'Dinheiro',
        };
    }
}
