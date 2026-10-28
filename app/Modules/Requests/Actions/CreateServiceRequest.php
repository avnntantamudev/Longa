<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O cliente publica um pedido: valida as regras de negócio, calcula a comissão, passa a "searching"
 * (expira em 5 min) e envia a 1.ª onda do TRIM TRIM.
 */
class CreateServiceRequest
{
    public function __construct(private readonly MatchingCoordinator $matching, private readonly AuditLogger $audit) {}

    /**
     * @param  array{category_id: int, title: string, description?: string|null, amount: int, scheduled_at: string, duration_minutes?: int, payment_method?: string, modality: string, province: string, municipality: string, neighborhood: string, latitude: float, longitude: float}  $data
     */
    public function handle(User $client, array $data, ?ServiceRequest $republishedFrom = null): ServiceRequest
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);

        $request = DB::transaction(function () use ($client, $data, $scheduledAt, $republishedFrom) {
            // Serializa os pedidos do mesmo cliente para os limites não serem contornados em paralelo
            User::query()->whereKey($client->id)->lockForUpdate()->first();

            $this->ensureWithinActiveLimit($client);
            $this->ensureNotDuplicate($client, (int) $data['category_id'], $scheduledAt);

            $split = Commission::split((int) $data['amount']);

            $request = new ServiceRequest([
                ...$data,
                'scheduled_at' => $scheduledAt,
                'commission' => $split['commission'],
                'provider_net' => $split['provider_net'],
                'republished_from_id' => $republishedFrom?->id,
            ]);
            $request->client_id = $client->id;
            $request->scheduled = $this->isScheduled($scheduledAt);
            $request->save();

            return $request;
        });

        $this->audit->record('requests.created', $request, ['amount' => $request->amount, 'republished_from' => $republishedFrom?->id], $client->id);

        $this->matching->start($request);

        return $request->refresh();
    }

    private function ensureWithinActiveLimit(User $client): void
    {
        $max = (int) config('longa.requests.max_active_per_client');
        $active = ServiceRequest::query()
            ->where('client_id', $client->id)
            ->whereIn('state', array_map(fn (RequestState $s) => $s->value, RequestState::active()))
            ->count();

        if ($active >= $max) {
            throw new ApiException('too_many_active_requests', "Já tem {$active} pedido(s) à procura de técnico. Aguarde ou cancele um deles.", 409);
        }
    }

    private function ensureNotDuplicate(User $client, int $categoryId, Carbon $scheduledAt): void
    {
        $window = (int) config('longa.requests.duplicate_window_minutes');

        $duplicate = ServiceRequest::query()
            ->where('client_id', $client->id)
            ->where('category_id', $categoryId)
            ->whereIn('state', [RequestState::Searching->value, RequestState::Matched->value])
            ->whereBetween('scheduled_at', [$scheduledAt->copy()->subMinutes($window), $scheduledAt->copy()->addMinutes($window)])
            ->exists();

        if ($duplicate) {
            throw new ApiException('duplicate_request', 'Já tem um pedido igual para essa altura.', 409);
        }
    }

    /**
     * Outro dia (não hoje, hora de Angola) e com o fecho (X h antes) ainda bem no futuro.
     */
    private function isScheduled(Carbon $scheduledAt): bool
    {
        $closeAt = $scheduledAt->copy()->subHours(app(PlatformSettings::class)->int('scheduled_request_close_hours'));

        return ! $scheduledAt->copy()->setTimezone('Africa/Luanda')->isSameDay(now('Africa/Luanda'))
            && $closeAt->gt(now()->addSeconds((int) config('longa.requests.ttl')));
    }
}
