<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Perfis da plataforma (spatie/laravel-permission). Um utilizador pode ter vários.
 */
enum Role: string
{
    case Client = 'client';
    case Provider = 'provider';
    case CompanyRh = 'company_rh';
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Provider => 'Técnico',
            self::CompanyRh => 'Empresa (RH)',
            self::Admin => 'Administrador',
            self::Operator => 'Operador',
        };
    }

    /**
     * Perfis que o próprio utilizador pode escolher no registo.
     *
     * @return list<self>
     */
    public static function selfAssignable(): array
    {
        return [self::Client, self::Provider, self::CompanyRh];
    }

    /**
     * Equipa do BISCA: entra no backoffice. O admin gere dinheiro e definições; o operador trata da operação
     * (verificações, sugestões de categoria, serviços e disputas só para ver).
     *
     * @return list<self>
     */
    public static function team(): array
    {
        return [self::Admin, self::Operator];
    }
}
