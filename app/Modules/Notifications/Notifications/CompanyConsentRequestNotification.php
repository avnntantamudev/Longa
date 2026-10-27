<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Uma empresa adicionou o utilizador como colaborador e pede para acompanhar as suas formações.
 */
class CompanyConsentRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CompanyEmployee $employee)
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
        return (new WebPushMessage)->title('Pedido da sua empresa')->body($this->body())->icon('/icons/icon-192.png')->tag('company-consent-'.$this->employee->id)->data(['url' => '/perfil']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['type' => 'company_consent_request', 'title' => 'Pedido da sua empresa', 'body' => $this->body(), 'url' => '/perfil'];
    }

    private function body(): string
    {
        return "{$this->employee->company->name} quer acompanhar as suas formações no Garimpo (aulas, horas e custos). Aceite ou recuse no seu perfil.";
    }
}
