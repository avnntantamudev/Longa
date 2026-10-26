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
 * Avisos de segurança da conta: novo aparelho, PIN trocado, aparelho bloqueado por tentativas erradas.
 */
class SecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const NEW_DEVICE = 'new_device';

    public const PIN_CHANGED = 'pin_changed';

    public const DEVICE_BLOCKED = 'device_blocked';

    public function __construct(public readonly string $kind, public readonly ?string $deviceName = null)
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

        return (new WebPushMessage)->title($title)->body($body)->icon('/icons/icon-192.png')->tag('security-'.$this->kind)->data(['url' => '/perfil']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        [$title, $body] = $this->content();

        return ['type' => 'security_'.$this->kind, 'title' => $title, 'body' => $body, 'url' => '/perfil'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        $device = $this->deviceName ?? 'um aparelho';

        return match ($this->kind) {
            self::NEW_DEVICE => ['Novo aparelho na sua conta', "Entrou na LONGA em {$device}. Se não foi você, remova-o em Perfil → Aparelhos e troque o PIN."],
            self::PIN_CHANGED => ['PIN alterado', 'O seu PIN foi alterado e os outros aparelhos saíram da conta. Se não foi você, entre com o código SMS e crie um PIN novo.'],
            default => ['Aparelho bloqueado', "O PIN foi errado muitas vezes em {$device}. Por segurança, esse aparelho precisa do código SMS para voltar a entrar."],
        };
    }
}
