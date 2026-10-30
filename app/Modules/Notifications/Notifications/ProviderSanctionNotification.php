<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Ratings\Models\ProviderSanction;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso, suspensão ou remoção do técnico por avaliações persistentemente más.
 */
class ProviderSanctionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ProviderSanction $sanction)
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
        [$title, $body] = $this->content();

        return (new WebPushMessage)->title($title)->body($body)->icon('/icons/icon-192.png')->data(['url' => '/perfil']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        [$title, $body] = $this->content();

        return ['type' => 'sanction_'.$this->sanction->level, 'title' => $title, 'body' => $body, 'url' => '/perfil'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        $average = number_format($this->sanction->average, 1, ',', '');

        return match ($this->sanction->level) {
            ProviderSanction::WARNING => ['Aviso sobre as suas avaliações', "A sua média recente é {$average}. Abaixo de 3,0 a conta é suspensa durante 7 dias."],
            ProviderSanction::SUSPENSION => ['Conta suspensa', "A sua média recente é {$average}. Não recebe pedidos até ".$this->sanction->ends_at?->format('d/m/Y H:i').'.'],
            default => ['Conta removida', 'A equipa do BISCA confirmou a remoção da sua conta de técnico por avaliações persistentemente más.'],
        };
    }
}
