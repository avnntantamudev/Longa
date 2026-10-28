<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Notifications\Channels\SmsChannel;
use App\Modules\Notifications\Contracts\SmsNotification;
use App\Modules\Notifications\Support\Formats;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * TRIM TRIM: "Novo pedido a 1,2 km: SGME 25.000 Kz".
 *
 * Canais: notificação na app (sempre); Web Push se o técnico tiver subscrições;
 * senão SMS (fallback), limitado por técnico e por hora porque custa dinheiro.
 * O push expira com a oferta (TTL = segundos restantes): uma oferta vencida não chega atrasada.
 */
class NewOfferNotification extends Notification implements ShouldQueue, SmsNotification
{
    use Queueable;

    public function __construct(public readonly RequestOffer $offer)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        } elseif (! $this->offer->request->scheduled && $this->allowSmsFallback($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * Não enviar se a oferta já não estiver aberta quando o job correr.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $channel === 'database' || $this->offer->fresh()?->isOpen() === true;
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('TRIM TRIM · '.$this->headline())
            ->body($this->details())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-maskable-192.png')
            ->tag('offer-'.$this->offer->id)
            ->renotify()
            ->requireInteraction()
            ->vibrate([200, 100, 200, 100, 400])
            ->data(['url' => '/tecnico/ofertas/'.$this->offer->id, 'offer_id' => $this->offer->id])
            ->options(['TTL' => max(1, $this->offer->secondsLeft()), 'urgency' => 'high']);
    }

    public function toSms(object $notifiable): string
    {
        return 'BISCA: '.$this->headline().'. '.$this->details().'. Responda em '.$this->offer->secondsLeft().'s: '
            .url('/tecnico/ofertas/'.$this->offer->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'offer',
            'title' => $this->headline(),
            'body' => $this->details(),
            'offer_id' => $this->offer->id,
            'url' => '/tecnico/ofertas/'.$this->offer->id,
        ];
    }

    private function headline(): string
    {
        $request = $this->offer->request;

        if ($request->scheduled) {
            return 'Pedido para '.Formats::when($request->scheduled_at).': '.$request->category->name.' '.Formats::kwanza($request->amount);
        }

        return 'Novo pedido a '.Formats::distance($this->offer->distance_m).': '.$request->category->name.' '.Formats::kwanza($request->amount);
    }

    private function details(): string
    {
        $request = $this->offer->request;

        return Formats::when($request->scheduled_at).' · '.$request->neighborhood;
    }

    private function allowSmsFallback(User $notifiable): bool
    {
        $key = 'offer-sms:'.$notifiable->id;
        $max = (int) config('longa.matching.sms_fallback_per_provider_per_hour');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }
}
