<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Driver de desenvolvimento: a mensagem (incluindo códigos OTP) fica em storage/logs/sms.log.
 * Nunca usar em produção.
 */
class LogSmsProvider implements SmsProvider
{
    public function send(string $to, string $message): void
    {
        Log::channel('sms')->info('SMS', ['to' => $to, 'message' => $message]);
    }
}
