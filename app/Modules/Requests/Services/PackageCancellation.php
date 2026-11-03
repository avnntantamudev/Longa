<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Notifications\Notifications\PackageUpdateNotification;
use App\Modules\Payments\Services\SessionSettlement;
use App\Modules\Requests\Actions\CreateServiceRequest;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Score\Services\ScoreRecorder;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Desistir de um pacote a meio (ou antes da primeira aula). As aulas que ainda não começaram são canceladas:
 *  - o técnico desiste: todas são devolvidas ao cliente e conta como cancelamento grave (vale por N nas sanções);
 *    o cliente pode depois procurar outro técnico para as aulas que faltam (replace());
 *  - o cliente desiste: são devolvidas, exceto a próxima se faltar menos de X horas (conta como dada).
 * As aulas já em curso ou terminadas seguem o seu ciclo normal. O pacote fecha quando não sobra nenhuma aula.
 */
class PackageCancellation
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionSettlement $settlement,
        private readonly ScoreRecorder $score,
        private readonly AuditLogger $audit,
        private readonly PlatformSettings $settings,
    ) {}

    public function cancel(ServiceRequest $package, User $actor, ?string $reason): ServiceRequest
    {
        $result = DB::transaction(function () use ($package, $actor, $reason) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($package->id);
            $byProvider = $locked->provider->user_id === $actor->id;

            if (! $locked->isPackage() || $locked->state !== RequestState::Accepted) {
                throw new ApiException('package_not_cancellable', 'Este pacote já não pode ser cancelado.', 409);
            }

            // Ainda por pagar: cancela-se sem dinheiro a mexer (não há aulas)
            if ($locked->awaitingPayment()) {
                $this->lifecycle->transition($locked, RequestState::Cancelled, 'cancelled', $actor, ['reason' => $reason], [
                    'closed_reason' => $byProvider ? 'provider_withdrew' : 'cancelled_by_client', 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'cancelled_at' => now(),
                ]);

                return ['package' => $locked, 'byProvider' => $byProvider, 'count' => 0];
            }

            $open = $locked->lessons()->whereIn('state', [RequestState::Accepted->value, RequestState::OnTheWay->value])->orderBy('scheduled_at')->get();
            if ($open->isEmpty()) {
                throw new ApiException('package_nothing_to_cancel', 'Já não há aulas por dar neste pacote.', 409);
            }

            $locked->forceFill(['closed_reason' => $byProvider ? 'provider_withdrew' : 'cancelled_by_client', 'cancel_reason' => $reason, 'cancelled_by' => $actor->id])->save();
            $lateUntil = now()->addHours((int) config('longa.packages.client_cancel_notice_hours'));

            foreach ($open as $i => $lesson) {
                // Cliente: a próxima aula conta como dada se faltar menos do que o aviso mínimo
                $late = ! $byProvider && $i === 0 && $lesson->scheduled_at->lt($lateUntil);
                $closed = $byProvider ? 'provider_withdrew' : ($late ? 'client_cancelled_late' : 'cancelled_by_client');
                $gross = $late ? $lesson->amount : 0;

                $this->lifecycle->transition($lesson, RequestState::Cancelled, 'cancelled', $actor, ['reason' => $reason, 'package' => true, 'provider_share' => $gross], [
                    'closed_reason' => $closed, 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'cancelled_at' => now(), 'reschedule_to' => null, 'reschedule_by' => null,
                ]);
                $this->settlement->split($lesson, $gross, $actor, $byProvider ? 'provider_withdrew' : 'client_cancellation');
            }

            // Técnico que desiste a meio: pesa como vários cancelamentos tardios
            if ($byProvider) {
                foreach (range(1, max(1, (int) config('longa.packages.provider_cancel_weight'))) as $n) {
                    $this->score->record((int) $locked->provider_profile_id, ScoreEvent::LATE_CANCELLATION, $locked, ['package_withdrawal' => true, 'weight' => $n]);
                }
            }

            return ['package' => $locked->refresh(), 'byProvider' => $byProvider, 'count' => $open->count()];
        });

        /** @var ServiceRequest $done */
        $done = $result['package'];
        $this->audit->record('packages.cancelled', $done, ['by_provider' => $result['byProvider'], 'lessons' => $result['count'], 'reason' => $reason], $actor->id);

        if ($result['byProvider']) {
            $done->client->notify(new PackageUpdateNotification('provider_withdrew', 'O técnico desistiu do pacote',
                "As {$result['count']} aulas que faltavam de \"{$done->title}\" vão ser devolvidas. Pode procurar outro técnico para essas aulas.", "/servicos/{$done->id}"));
        } else {
            $done->provider->user->notify(new PackageUpdateNotification('client_cancelled', 'O cliente cancelou o pacote',
                "As aulas que faltavam de \"{$done->title}\" foram canceladas.", "/servicos/{$done->id}"));
        }

        return $done;
    }

    /**
     * Datas das aulas que o técnico deixou e que ainda dão para um novo pedido (com antecedência suficiente).
     *
     * @return list<Carbon>
     */
    public function replaceableDates(ServiceRequest $package): array
    {
        if (! $package->isPackage() || $package->closed_reason !== 'provider_withdrew') {
            return [];
        }
        $lead = $this->settings->int('scheduled_request_close_hours') * 60 + $this->settings->int('packages_vip_window_minutes') + 60;

        return $package->lessons()->where('closed_reason', 'provider_withdrew')->where('scheduled_at', '>=', now()->addMinutes($lead))
            ->orderBy('scheduled_at')->pluck('scheduled_at')->map(fn ($d) => Carbon::parse($d))->values()->all();
    }

    /**
     * Procurar outro técnico para as aulas que faltam (depois de o técnico desistir): novo pedido com essas datas,
     * os mesmos dados e o mesmo preço máximo. Paga-se quando alguém aceitar. Idempotente (um só novo pedido).
     */
    public function replace(ServiceRequest $package, User $client): ServiceRequest
    {
        if ($package->client_id !== $client->id) {
            throw new ApiException('forbidden', 'Este pacote não é seu.', 403);
        }
        $existing = ServiceRequest::query()->where('republished_from_id', $package->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $dates = $this->replaceableDates($package);
        if ($dates === []) {
            throw new ApiException('package_not_replaceable', 'Já não há aulas com tempo para procurar outro técnico. Faça um novo pedido.', 409);
        }

        $many = count($dates) >= (int) config('longa.packages.min_sessions');
        $data = [
            'category_id' => $package->category_id,
            'title' => (string) preg_replace('/ · aula \d+\/\d+$/', '', (string) $package->title),
            'description' => $package->description,
            'answers' => $package->answers ?? [],
            'max_hourly_rate' => $package->max_hourly_rate,
            'duration_minutes' => $package->duration_minutes,
            'payment_method' => 'electronic',
            'modality' => $package->modality,
            'province' => $package->province,
            'municipality' => $package->municipality,
            'neighborhood' => $package->neighborhood,
            'latitude' => $package->latitude,
            'longitude' => $package->longitude,
            'scheduled_at' => $dates[0]->toIso8601String(),
            'lesson_dates' => $many ? array_map(fn (Carbon $d) => $d->toIso8601String(), $dates) : null,
        ];

        return app(CreateServiceRequest::class)->handle($client, $data, $package);
    }
}
