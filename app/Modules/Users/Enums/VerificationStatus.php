<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Estado de verificação do técnico (resumo das verificações aprovadas).
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    // Decididos pela equipa depois de aprovado: deixam de receber pedidos (suspenso pode voltar; removido não)
    case Suspended = 'suspended';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Por verificar',
            self::Pending => 'Pendente',
            self::Verified => 'Aprovado',
            self::Rejected => 'Rejeitado',
            self::Suspended => 'Suspenso',
            self::Removed => 'Removido',
        };
    }
}
