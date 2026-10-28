<?php

declare(strict_types=1);

namespace App\Modules\Auth\Jobs;

use App\Modules\Auth\Enums\OtpChannel;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Notifications\WhatsApp\WhatsAppDeliveryException;
use App\Modules\Notifications\WhatsApp\WhatsAppProvider;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envia o código de acesso pelo canal escolhido pelo utilizador (fila "notifications").
 * O payload vai cifrado na fila (contém o código).
 *
 * Se o WhatsApp falhar (ex.: número sem WhatsApp), envia por SMS para o utilizador não ficar sem código.
 */
class SendOtpCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        public readonly string $phone,
        public readonly string $code,
        public readonly int $minutes,
        public readonly OtpChannel $channel,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(SmsProvider $sms, WhatsAppProvider $whatsApp): void
    {
        if ($this->channel === OtpChannel::WhatsApp) {
            try {
                $whatsApp->sendOtp($this->phone, $this->code);

                return;
            } catch (WhatsAppDeliveryException $e) {
                Log::warning('otp.whatsapp_failed_fallback_sms', ['reason' => $e->getMessage()]);
            }
        }

        $sms->send($this->phone, "BISCA: o seu código é {$this->code}. Válido durante {$this->minutes} minutos. Não o partilhe com ninguém.");
    }
}
