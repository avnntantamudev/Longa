<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Como se define o preço de um serviço da categoria.
 */
enum PricingMode: string
{
    // Valor da tabela da categoria, conhecido e bloqueado no envio do pedido
    case Fixed = 'fixed';
    // O técnico vai ao local (com taxa de visita opcional) e envia um orçamento que o cliente aceita ou recusa
    case Quote = 'quote';
    // Preço por hora ou pacote definido pelo técnico dentro dos limites da categoria (ex.: aulas)
    case HourlyPackage = 'hourly_package';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Preço fixo',
            self::Quote => 'Por orçamento',
            self::HourlyPackage => 'Por hora ou pacote',
        };
    }
}
