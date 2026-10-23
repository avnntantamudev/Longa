<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

/**
 * Envio de SMS. O driver é escolhido por SMS_DRIVER (config/longa.php):
 *  - "log": escreve a mensagem no canal de log "sms" (desenvolvimento);
 *  - "array": guarda em memória (testes);
 *  - provedor real: por definir com o cliente (implementar esta interface e registar em NotificationsServiceProvider).
 */
interface SmsProvider
{
    /**
     * @param  string  $to  telefone em E.164
     *
     * @throws SmsDeliveryException quando o provedor recusa ou falha
     */
    public function send(string $to, string $message): void;
}
