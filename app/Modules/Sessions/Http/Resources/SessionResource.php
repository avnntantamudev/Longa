<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ProviderRebookInterest;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\LessonReschedules;
use App\Modules\Requests\Services\PackageCancellation;
use App\Modules\Sessions\Services\AvailableActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Execução do pedido vista por um dos participantes (o serviço é o próprio pedido). Depois da aceitação:
 *  - o cliente vê os dados públicos completos do técnico (sem contactos: a comunicação é pelo chat);
 *  - o técnico vê o nome do cliente e o local exato.
 * Os valores mostram ao técnico quanto recebe (valor − comissão).
 *
 * @mixin ServiceRequest
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getAuthIdentifier();
        $isProvider = $this->provider->user_id === $viewerId;

        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'actions' => app(AvailableActions::class)->for($this->resource, $isProvider),
            'request_id' => $this->id,
            'title' => $this->title,
            'category' => $this->category->name,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'modality' => $this->modality,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'duration_minutes' => $this->duration_minutes,
            'ends_at' => $this->ends_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'closed_reason' => $this->closed_reason,
            'pricing_mode' => $this->pricing_mode->value,
            /** Pagamento por fases: sequência, %, valor e estado (pending | due | paid | released) */
            'phases' => $this->phases()->get(['id', 'sequence', 'percent', 'amount', 'status'])->toArray(),
            'visit_fee' => $this->visit_fee,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmation_deadline' => $this->completed_at?->copy()->addHours((int) config('longa.session_rules.confirmation_window_hours'))->toIso8601String(),
            'no_show_contest_until' => $this->no_show_contest_until?->toIso8601String(),
            'cash_confirmed_at' => $this->cash_confirmed_at?->toIso8601String(),
            'amount' => $this->amount,
            'commission' => $this->when($isProvider, $this->commission),
            'provider_net' => $this->when($isProvider, $this->provider_net),
            'commission_percent' => $this->when($isProvider, $this->commission_percent),
            'role' => $isProvider ? 'provider' : 'client',
            'conversation_id' => $this->conversation?->id,
            'client' => $this->when($isProvider, fn () => ['name' => $this->client->name, ...$this->client->photoUrls(), 'rating' => app(RatingService::class)->clientAverage($this->client)]),
            'provider' => $this->when(! $isProvider, fn () => [
                'id' => $this->provider->id,
                'name' => $this->provider->user->name,
                ...$this->provider->user->photoUrls(),
                'bio' => $this->provider->bio,
                'years_experience' => $this->provider->years_experience,
                'neighborhood' => $this->provider->neighborhood,
                'verified' => $this->provider->isVerified(),
                'vip' => app(VipSubscriptions::class)->isVip($this->provider),
                'score' => $this->provider->score,
                'rating_average' => $this->provider->rating_average,
                'rating_count' => $this->provider->rating_count,
            ]),
            /**
             * Voltar a chamar o técnico (cliente, serviço pago): "direct" = técnico VIP, pedido direto;
             * "interest" = técnico sem VIP, só regista o interesse. null = não disponível.
             */
            'rebook' => $this->rebook($isProvider),
            /** Aula de um pacote: nº da aula, total, remarcação proposta e remarcações que sobram. */
            'lesson' => $this->isPackageLesson() ? [
                'package_id' => $this->package_id,
                'sequence' => $this->package_sequence,
                'total' => (int) ServiceRequest::query()->whereKey($this->package_id)->value('sessions'),
                'reschedule_to' => $this->reschedule_to?->toIso8601String(),
                'reschedule_by_me' => $this->reschedule_to !== null && $this->reschedule_by === $viewerId,
                'reschedules_left' => app(LessonReschedules::class)->remaining($this->resource),
            ] : null,
            /** Pedido-contrato de um pacote: as aulas e se pode procurar outro técnico (o técnico desistiu). */
            'package' => $this->isPackage() ? [
                'lessons' => $this->lessons()->get()->map(fn (ServiceRequest $l) => [
                    'id' => $l->id, 'sequence' => $l->package_sequence, 'scheduled_at' => $l->scheduled_at->toIso8601String(),
                    'state' => $l->state->value, 'state_label' => $l->state->label(), 'closed_reason' => $l->closed_reason,
                ])->all(),
                'can_cancel' => $this->state === RequestState::Accepted,
                'replaceable' => ! $isProvider && app(PackageCancellation::class)->replaceableDates($this->resource) !== []
                    && ! ServiceRequest::query()->where('republished_from_id', $this->id)->exists(),
                'closed_reason' => $this->closed_reason,
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{mode: 'direct'|'interest', category_id: int, interest_sent: bool}|null
     */
    private function rebook(bool $isProvider): ?array
    {
        if ($isProvider || $this->state !== RequestState::Paid || $this->isPackageLesson()) {
            return null;
        }

        return [
            'mode' => app(VipSubscriptions::class)->isVip($this->provider) ? 'direct' : 'interest',
            'category_id' => $this->category_id,
            'interest_sent' => ProviderRebookInterest::forProvider((int) $this->provider_profile_id)->where('client_id', $this->client_id)->exists(),
        ];
    }
}
