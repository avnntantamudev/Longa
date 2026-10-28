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

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Aluno',
            self::Provider => 'Formador',
            self::CompanyRh => 'Empresa (RH)',
            self::Admin => 'Administrador',
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
}
