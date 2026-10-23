<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;

/**
 * Republicar = criar um pedido novo com os dados do original (expirado ou cancelado), ligado a ele.
 * Nova data e novo valor são opcionais; sem nova data, a original tem de continuar no futuro.
 */
class RepublishTrainingRequest
{
    public function __construct(private readonly CreateTrainingRequest $create) {}

    public function handle(TrainingRequest $original, User $student, ?string $scheduledAt, ?int $amount): TrainingRequest
    {
        if (! in_array($original->state, [RequestState::Expired, RequestState::Cancelled], true)) {
            throw new ApiException('request_not_republishable', 'Só pode republicar pedidos expirados ou cancelados.', 409);
        }

        $lead = (int) config('longa.requests.min_lead_minutes');
        if ($scheduledAt === null && $original->scheduled_at->lt(now()->addMinutes($lead))) {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, [
                'scheduled_at' => ['A data original já passou. Escolha uma nova data.'],
            ]);
        }

        return $this->create->handle($student, [
            'skill_id' => $original->skill_id,
            'title' => $original->title,
            'description' => $original->description,
            'amount' => $amount ?? $original->amount,
            'scheduled_at' => $scheduledAt ?? $original->scheduled_at->toIso8601String(),
            'modality' => $original->modality,
            'municipality' => $original->municipality,
            'neighborhood' => $original->neighborhood,
            'latitude' => $original->latitude,
            'longitude' => $original->longitude,
        ], $original);
    }
}
