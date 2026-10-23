<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * Canal por onde o utilizador escolhe receber o código de acesso.
 */
enum OtpChannel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
