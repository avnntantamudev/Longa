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
 * Um cliente quis voltar a chamar um técnico que não é VIP: avisa o técnico de que, com o VIP,
 * os clientes podem pedir-lhe diretamente. Sem o nome do cliente (é só um sinal de procura).
 */
class RebookInterestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $total)
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
        return (new WebPushMessage)->title('Um cliente quer voltar a chamá-lo')->body($this->body())->icon('/icons/icon-192.png')->tag('rebook-interest')->data(['url' => '/vip']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => 'rebook_interest', 'title' => 'Um cliente quer voltar a chamá-lo', 'body' => $this->body(), 'url' => '/vip', 'total' => $this->total];
    }

    private function body(): string
    {
        $who = $this->total === 1 ? 'Um cliente quis' : "{$this->total} clientes quiseram";

        return "{$who} voltar a chamá-lo. Com o VIP, os clientes podem pedir-lhe diretamente.";
    }
}
