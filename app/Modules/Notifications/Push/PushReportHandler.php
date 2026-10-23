<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Resultado de cada envio Web Push:
 *  - sucesso: atualiza last_used_at da subscrição;
 *  - subscrição expirada/inválida (404 ou 410 do serviço de push): apaga-a;
 *  - outros erros: só regista no log (a fila volta a tentar).
 */
class PushReportHandler implements ReportHandlerInterface
{
    public function handleReport(MessageSentReport $report, PushSubscription $subscription, WebPushMessageInterface $message): void
    {
        if ($report->isSuccess()) {
            $subscription->forceFill(['last_used_at' => now()])->saveQuietly();

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
            Log::info('push.subscription_removed', ['subscription_id' => $subscription->getKey(), 'status' => $report->getResponse()?->getStatusCode()]);

            return;
        }

        Log::warning('push.failed', [
            'subscription_id' => $subscription->getKey(),
            'status' => $report->getResponse()?->getStatusCode(),
            'reason' => $report->getReason(),
        ]);
    }
}
