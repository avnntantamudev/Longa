<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Novidades de um pacote de aulas (desistência, remarcação proposta, aceite ou recusada).
 */
class PackageUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $kind, public readonly string $title, public readonly string $body, public readonly string $url)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->pushSubscriptions()->exists() ? ['database', WebPushChannel::class] : ['database'];
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title($this->title)->body($this->body)->icon('/icons/icon-192.png')->tag("package-{$this->kind}")->data(['url' => $this->url]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => "package_{$this->kind}", 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
