<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Notifications\Notifications\SessionUpdateNotification as Update;
use App\Modules\Notifications\Push\PushReportHandler;
use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Notifications\WhatsApp\ArrayWhatsAppProvider;
use App\Modules\Notifications\WhatsApp\CloudApiWhatsAppProvider;
use App\Modules\Notifications\WhatsApp\LogWhatsAppProvider;
use App\Modules\Notifications\WhatsApp\WhatsAppProvider;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Events\DisputeOpened;
use App\Modules\Sessions\Events\DisputeResolved;
use App\Modules\Sessions\Events\QrIssued;
use App\Modules\Sessions\Events\SessionCancelled;
use App\Modules\Sessions\Events\SessionConfirmed;
use App\Modules\Sessions\Events\SessionStarted;
use App\Modules\Sessions\Models\Dispute;
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

        $this->app->singleton(WhatsAppProvider::class, function (): WhatsAppProvider {
            $config = config('longa.whatsapp');

            return match ($config['driver']) {
                'log' => new LogWhatsAppProvider,
                'array' => new ArrayWhatsAppProvider,
                'cloud' => new CloudApiWhatsAppProvider(
                    (string) $config['token'],
                    (string) $config['phone_number_id'],
                    (string) $config['api_version'],
                    (string) $config['otp_template'],
                    (string) $config['language'],
                ),
                default => throw new InvalidArgumentException("Driver de WhatsApp não suportado: {$config['driver']}"),
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

        // Execução da aula: avisa quem não fez a ação
        Event::listen(SessionStarted::class, fn (SessionStarted $e) => $e->session->student->notify(new Update($e->session, Update::STARTED)));
        Event::listen(QrIssued::class, function (QrIssued $e) {
            // Só no primeiro lote (os seguintes são renovações do QR no ecrã do formador)
            if ($e->session->events()->where('type', 'qr_issued')->count() <= 1) {
                $e->session->student->notify(new Update($e->session, Update::QR_READY));
            }
        });
        Event::listen(SessionConfirmed::class, fn (SessionConfirmed $e) => $e->session->trainer->user->notify(new Update($e->session, Update::CONFIRMED)));
        Event::listen(SessionCancelled::class, function (SessionCancelled $e) {
            $other = $e->session->state === SessionState::CancelledByStudent ? $e->session->trainer->user : $e->session->student;
            $reason = $e->session->events()->where('type', 'cancelled')->latest('id')->first()?->metadata['reason'] ?? null;
            $other->notify(new Update($e->session, Update::CANCELLED, is_string($reason) ? $reason : null));
        });
        Event::listen(DisputeOpened::class, function (DisputeOpened $e) {
            $openedBy = $e->session->disputes()->where('status', Dispute::OPEN)->value('opened_by');
            $other = $openedBy === $e->session->student_id ? $e->session->trainer->user : $e->session->student;
            $other->notify(new Update($e->session, Update::DISPUTE_OPENED));
        });
        Event::listen(DisputeResolved::class, function (DisputeResolved $e) {
            foreach ([$e->session->student, $e->session->trainer->user] as $user) {
                $user->notify(new Update($e->session, Update::DISPUTE_RESOLVED, $e->dispute->resolution));
            }
        });
    }
}
