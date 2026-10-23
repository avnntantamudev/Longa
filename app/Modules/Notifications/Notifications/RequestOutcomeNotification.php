<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Resultado do pedido para o aluno: formador encontrado ou sem formadores (com opção de republicar).
 * Canais: notificação na app e Web Push (se houver subscrição).
 */
class RequestOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const MATCHED = 'matched';

    public const EXPIRED = 'expired';

    /**
     * @param  self::MATCHED|self::EXPIRED  $outcome
     */
    public function __construct(public readonly TrainingRequest $request, public readonly string $outcome, public readonly ?int $sessionId = null)
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
            ->title($this->title())
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->tag('request-'.$this->request->id)
            ->data(['url' => $this->url()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'request_'.$this->outcome,
            'title' => $this->title(),
            'body' => $this->body(),
            'request_id' => $this->request->id,
            'session_id' => $this->sessionId,
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return $this->outcome === self::MATCHED ? 'Formador encontrado!' : 'Sem formadores disponíveis';
    }

    private function body(): string
    {
        return $this->outcome === self::MATCHED
            ? "A sua aula \"{$this->request->title}\" está marcada. Veja os dados do formador."
            : "Ninguém aceitou \"{$this->request->title}\" a tempo. Pode republicar o pedido, com outro valor ou hora.";
    }

    private function url(): string
    {
        return $this->outcome === self::MATCHED && $this->sessionId ? '/aulas/'.$this->sessionId : '/pedidos/'.$this->request->id;
    }
}
