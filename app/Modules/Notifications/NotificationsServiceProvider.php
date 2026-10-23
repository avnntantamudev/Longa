<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Notifications\Push\PushReportHandler;
use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Events\RequestMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushChannel;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function (): SmsProvider {
            $driver = config('longa.sms.driver');

            return match ($driver) {
                'log' => new LogSmsProvider,
                'array' => new ArraySmsProvider,
                // O provedor real será integrado depois de o cliente o escolher
                default => throw new InvalidArgumentException("Driver de SMS não suportado: {$driver}"),
            };
        });
    }

    public function boot(): void
    {
        // Substitui o ReportHandler do pacote: atualiza last_used_at e apaga subscrições inválidas
        $this->app->when(WebPushChannel::class)->needs(ReportHandlerInterface::class)->give(PushReportHandler::class);

        // Eventos de domínio → notificações (push / SMS / na app), enviadas pela fila "notifications"
        Event::listen(OfferSent::class, function (OfferSent $event) {
            $event->offer->trainer->user->notify(new NewOfferNotification($event->offer));
        });

        Event::listen(RequestMatched::class, function (RequestMatched $event) {
            $event->request->student->notify(new RequestOutcomeNotification($event->request, RequestOutcomeNotification::MATCHED, $event->session->id));
        });

        Event::listen(RequestExpired::class, function (RequestExpired $event) {
            $event->request->student->notify(new RequestOutcomeNotification($event->request, RequestOutcomeNotification::EXPIRED));
        });
    }
}
