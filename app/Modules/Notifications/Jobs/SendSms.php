<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia um SMS pela fila "notifications".
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly string $to, public readonly string $message)
    {
        $this->onQueue('notifications');
    }

    public function handle(SmsProvider $sms): void
    {
        $sms->send($this->to, $this->message);
    }
}
