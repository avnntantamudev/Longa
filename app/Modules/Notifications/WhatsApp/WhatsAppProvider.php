<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

/**
 * Envio por WhatsApp. Driver em WHATSAPP_DRIVER (config/longa.php → whatsapp):
 *  - "log": escreve no canal de log "sms" (desenvolvimento);
 *  - "array": guarda em memória (testes);
 *  - "cloud": WhatsApp Cloud API da Meta (modelo de autenticação aprovado; exige conta Meta Business).
 *
 * Mensagens iniciadas pela empresa (como códigos) são pagas pela Meta por mensagem entregue.
 */
interface WhatsAppProvider
{
    /**
     * Envia um código de acesso com o modelo de autenticação (botão "copiar código").
     *
     * @param  string  $to  telefone em E.164
     *
     * @throws WhatsAppDeliveryException quando a Meta recusa ou falha (ex.: número sem WhatsApp)
     */
    public function sendOtp(string $to, string $code): void;
}
