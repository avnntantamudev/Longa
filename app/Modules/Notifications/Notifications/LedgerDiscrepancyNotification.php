<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Users\Models\User;
use App\Modules\Wallet\Models\LedgerReconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Alerta aos administradores: a reconciliação do ledger encontrou diferenças.
 */
class LedgerDiscrepancyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LedgerReconciliation $reconciliation)
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
            ->title('Alerta financeiro')
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->tag('ledger-discrepancy')
            ->data(['url' => '/backoffice']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => 'ledger_discrepancy', 'title' => 'Alerta financeiro', 'body' => $this->body(), 'reconciliation_id' => $this->reconciliation->id, 'url' => '/backoffice'];
    }

    private function body(): string
    {
        return 'A reconciliação do ledger encontrou '.count($this->reconciliation->issues).' problema(s). Verifique o backoffice.';
    }
}
