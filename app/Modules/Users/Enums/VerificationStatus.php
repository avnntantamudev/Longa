<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Estado de verificação do formador (resumo das verificações aprovadas).
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
