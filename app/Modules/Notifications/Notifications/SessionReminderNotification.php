<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Lembrete ao aluno: a aula está à espera da sua confirmação (ou disputa) e o prazo está a acabar.
 */
class SessionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TrainingSession $session, public readonly int $hoursLeft)
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
            ->title('Confirme a sua aula')
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->tag('session-reminder-'.$this->session->id)
            ->data(['url' => '/aulas/'.$this->session->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => 'session_reminder', 'title' => 'Confirme a sua aula', 'body' => $this->body(), 'session_id' => $this->session->id, 'url' => '/aulas/'.$this->session->id];
    }

    private function body(): string
    {
        return "\"{$this->session->title}\": confirme que a aula aconteceu ou abra uma disputa. Faltam cerca de {$this->hoursLeft} h; depois disso a aula é resolvida automaticamente.";
    }
}
