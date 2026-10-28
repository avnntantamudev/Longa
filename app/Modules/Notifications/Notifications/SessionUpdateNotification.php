<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisos da execução do serviço (push + na app). Complementa os eventos em tempo real, que só chegam
 * com a app aberta.
 */
class SessionUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const STARTED = 'started';

    public const QR_READY = 'qr_ready';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const DISPUTE_OPENED = 'dispute_opened';

    public const DISPUTE_RESOLVED = 'dispute_resolved';

    public function __construct(public readonly ServiceJob $session, public readonly string $kind, public readonly ?string $detail = null)
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

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/icons/icon-192.png')
            ->tag("session-{$this->kind}-{$this->session->id}")
            ->data(['url' => '/servicos/'.$this->session->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        [$title, $body] = $this->content();

        return ['type' => 'session_'.$this->kind, 'title' => $title, 'body' => $body, 'session_id' => $this->session->id, 'url' => '/servicos/'.$this->session->id];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        $title = "\"{$this->session->title}\"";

        return match ($this->kind) {
            self::STARTED => ['O serviço começou', "{$title} foi iniciado pelo técnico."],
            self::QR_READY => ['Confirme o serviço', "{$title} terminou. Escaneie o QR do técnico para confirmar."],
            self::CONFIRMED => ['Serviço confirmado', $this->session->isCash()
                ? "{$title} foi confirmada. O pagamento foi feito em mão."
                : "{$title} foi confirmada. Kz ".number_format($this->session->provider_net, 0, ',', '.').' disponíveis na sua carteira.'],
            self::CANCELLED => ['Serviço cancelado', "{$title} foi cancelado.".($this->detail ? " Motivo: {$this->detail}" : '')],
            self::DISPUTE_OPENED => ['Disputa aberta', "Foi aberta uma disputa sobre {$title}. O valor fica retido até à decisão do BISCA."],
            default => ['Disputa decidida', "A disputa sobre {$title} foi decidida: ".match ($this->detail) {
                'released' => 'valor libertado ao técnico.',
                'refunded' => 'reembolso total ao cliente.',
                default => 'reembolso parcial ao cliente.',
            }],
        };
    }
}
