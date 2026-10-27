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
 * O Garimpo removeu a foto de perfil (imprópria ou diferente do BI): carregar outra.
 */
class PhotoRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $reason)
    {
        $this->onQueue('notifications');
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
        return (new WebPushMessage)
            ->title('Carregue outra foto')
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->tag('photo-rejected')
            ->data(['url' => '/perfil']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => 'photo_rejected', 'title' => 'Carregue outra foto', 'body' => $this->body(), 'url' => '/perfil'];
    }

    private function body(): string
    {
        return "A sua foto de perfil foi removida: {$this->reason}. Carregue uma foto em que se veja bem o seu rosto.";
    }
}
