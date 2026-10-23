<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

/**
 * Driver de testes: guarda os códigos enviados; `failNext` simula um número sem WhatsApp.
 */
class ArrayWhatsAppProvider implements WhatsAppProvider
{
    /** @var list<array{to: string, code: string}> */
    public array $sent = [];

    public bool $failNext = false;

    public function sendOtp(string $to, string $code): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new WhatsAppDeliveryException('Número sem WhatsApp (simulado).');
        }

        $this->sent[] = ['to' => $to, 'code' => $code];
    }
}
