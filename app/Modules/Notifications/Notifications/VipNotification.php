<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisos da subscrição VIP: ativada, a terminar (30/7/1 dias) e terminada.
 */
class VipNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ACTIVATED = 'activated';

    public const ENDING = 'ending';

    public const ENDED = 'ended';

    public function __construct(public readonly string $kind, public readonly Carbon $until, public readonly int $daysLeft = 0)
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
        [$title, $body] = $this->content();

        return (new WebPushMessage)->title($title)->body($body)->icon('/icons/icon-192.png')->tag('vip-'.$this->kind)->data(['url' => '/vip']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        [$title, $body] = $this->content();

        return ['type' => 'vip_'.$this->kind, 'title' => $title, 'body' => $body, 'url' => '/vip'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        $date = $this->until->format('d/m/Y');

        return match ($this->kind) {
            self::ACTIVATED => ['Agora é VIP', "É formador VIP até {$date}: pedidos de outro dia em toda a província, prioridade e comissão reduzida."],
            self::ENDING => ['O seu VIP está a terminar', "Faltam {$this->daysLeft} dia(s): o VIP termina a {$date}. Renove para não perder as vantagens."],
            default => ['O seu VIP terminou', "Terminou a {$date}. Renove para voltar a ter as vantagens VIP."],
        };
    }
}
