<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

/**
 * Driver de testes: guarda as mensagens enviadas em memória.
 */
class ArraySmsProvider implements SmsProvider
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /**
     * @return list<string>
     */
    public function messagesTo(string $to): array
    {
        return array_values(array_map(
            fn (array $sms) => $sms['message'],
            array_filter($this->sent, fn (array $sms) => $sms['to'] === $to),
        ));
    }
}
