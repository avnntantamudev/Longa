<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

/**
 * Notificação que pode ser enviada por SMS (texto curto, sem dados privados).
 */
interface SmsNotification
{
    public function toSms(object $notifiable): string;
}
