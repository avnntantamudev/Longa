<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\SmsNotification;
use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Notifications\Notification;

/**
 * Canal de notificações por SMS (usa o SmsProvider configurado: "log" em desenvolvimento).
 * Uso numa notificação: via() devolve SmsChannel::class e a notificação implementa SmsNotification.
 */
class SmsChannel
{
    public function __construct(private readonly SmsProvider $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof SmsNotification || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (is_string($to) && $to !== '') {
            $this->sms->send($to, $notification->toSms($notifiable));
        }
    }
}
