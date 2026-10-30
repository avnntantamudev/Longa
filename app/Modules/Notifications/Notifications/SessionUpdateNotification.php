<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Notifications\Channels\SmsChannel;
use App\Modules\Notifications\Contracts\SmsNotification;
use App\Modules\Notifications\Support\Formats;
use App\Modules\Requests\Models\ServiceRequest;
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
class SessionUpdateNotification extends Notification implements ShouldQueue, SmsNotification
{
    use Queueable;

    public const ON_THE_WAY = 'on_the_way';

    public const QUOTE_SENT = 'quote_sent';

    public const QUOTE_ACCEPTED = 'quote_accepted';

    public const QUOTE_REFUSED = 'quote_refused';

    public const QUOTE_REVISION = 'quote_revision';

    /** Concluído automaticamente (sem o técnico o marcar): começa a janela de confirmação */
    public const COMPLETED = 'completed';

    public const PAYMENT_RECEIVED = 'payment_received';

    public const ADDENDUM_PROPOSED = 'addendum_proposed';

    public const ADDENDUM_DECIDED = 'addendum_decided';

    /** Sem push ativo, estes avisos vão por SMS (custam dinheiro: só os que não podem esperar) */
    private const SMS_FALLBACK = [self::ON_THE_WAY, self::QUOTE_SENT, self::QUOTE_ACCEPTED, self::PAYMENT_RECEIVED, self::DISPUTE_OPENED];

    public const STARTED = 'started';

    public const QR_READY = 'qr_ready';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const DISPUTE_OPENED = 'dispute_opened';

    public const DISPUTE_RESOLVED = 'dispute_resolved';

    /** Lembrete antes de um serviço marcado (detalhe: "24h" ou "1h") */
    public const REMINDER = 'reminder';

    public function __construct(public readonly ServiceRequest $session, public readonly string $kind, public readonly ?string $detail = null)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        if ($notifiable->pushSubscriptions()->exists()) {
            return ['database', WebPushChannel::class];
        }

        return in_array($this->kind, self::SMS_FALLBACK, true) ? ['database', SmsChannel::class] : ['database'];
    }

    public function toSms(object $notifiable): string
    {
        [$title, $body] = $this->content();

        return "BISCA: {$title}. {$body}";
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
            self::QUOTE_SENT => ['Orçamento recebido', "{$title}: o técnico enviou o orçamento (".Formats::kwanza((int) $this->detail).'). Veja e responda na app.'],
            self::QUOTE_ACCEPTED => ['Orçamento aceite', "{$title}: o cliente aceitou o orçamento. O preço fica bloqueado."],
            self::QUOTE_REFUSED => ['Orçamento recusado', "{$title}: o cliente recusou o orçamento."],
            self::QUOTE_REVISION => ['Revisão do orçamento', "{$title}: o cliente pediu uma revisão do orçamento.".($this->detail ? " Nota: {$this->detail}" : '')],
            self::COMPLETED => ['Serviço concluído', "{$title} foi dado como concluído. Confirme na app ou abra uma disputa dentro do prazo."],
            self::PAYMENT_RECEIVED => ['Pagamento recebido', "{$title}: o cliente pagou ".Formats::kwanza((int) $this->detail).'. O valor fica retido até à confirmação.'],
            self::ADDENDUM_PROPOSED => ['Trabalho extra proposto', "{$title}: o técnico propôs trabalho extra (".Formats::kwanza((int) $this->detail).'). Aprove ou recuse na app.'],
            self::ADDENDUM_DECIDED => ['Trabalho extra', "{$title}: ".($this->detail === 'approved' ? 'o trabalho extra foi aprovado.' : 'o trabalho extra foi recusado.')],
            self::ON_THE_WAY => ['O técnico está a caminho', "{$title}: o técnico já saiu para o local."],
            self::STARTED => ['O serviço começou', "{$title} foi iniciado pelo técnico."],
            self::REMINDER => ['Lembrete do serviço', "{$title} é ".($this->detail === '1h' ? 'daqui a 1 hora' : 'amanhã').', às '.$this->session->scheduled_at->format('H:i')
                .($this->session->modality === 'online' ? ' (online).' : ', em '.$this->session->neighborhood.'.')],
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
