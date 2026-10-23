<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Perfis da plataforma (spatie/laravel-permission). Um utilizador pode ter vários.
 */
enum Role: string
{
    case Student = 'student';
    case Trainer = 'trainer';
    case CompanyRh = 'company_rh';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Aluno',
            self::Trainer => 'Formador',
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
        return [self::Student, self::Trainer, self::CompanyRh];
    }
}
