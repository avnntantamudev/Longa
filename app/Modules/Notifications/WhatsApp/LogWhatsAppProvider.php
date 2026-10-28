<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Driver de desenvolvimento: a mensagem fica em storage/logs/sms-*.log (marcada como WhatsApp).
 */
class LogWhatsAppProvider implements WhatsAppProvider
{
    public function sendOtp(string $to, string $code): void
    {
        Log::channel('sms')->info('WhatsApp', ['to' => $to, 'message' => "BISCA (WhatsApp): o seu código é {$code}."]);
    }
}
