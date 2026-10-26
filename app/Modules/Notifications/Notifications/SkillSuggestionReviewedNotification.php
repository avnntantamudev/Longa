<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Users\Models\SkillSuggestion;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Decisão sobre uma área sugerida pelo formador.
 */
class SkillSuggestionReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SkillSuggestion $suggestion)
    {
        $this->onQueue('notifications');
        $this->afterCommit(); // a decisão é gravada numa transação
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

        return (new WebPushMessage)->title($title)->body($body)->icon('/icons/icon-192.png')->tag('skill-suggestion-'.$this->suggestion->id)->data(['url' => '/perfil']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        [$title, $body] = $this->content();

        return ['type' => 'skill_suggestion_'.$this->suggestion->status, 'title' => $title, 'body' => $body, 'url' => '/perfil'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function content(): array
    {
        if ($this->suggestion->status === SkillSuggestion::APPROVED) {
            return ['Área aprovada', "\"{$this->suggestion->name}\" foi aprovada como \"{$this->suggestion->skill?->name}\". Já recebe pedidos desta área."];
        }

        return ['Área não aprovada', "\"{$this->suggestion->name}\" não foi aprovada: {$this->suggestion->review_note}"];
    }
}
