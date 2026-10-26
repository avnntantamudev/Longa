<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O aluno publica um pedido: valida as regras de negócio, calcula a comissão, passa a "searching"
 * (expira em 5 min) e envia a 1.ª onda do TRIM TRIM.
 */
class CreateTrainingRequest
{
    public function __construct(private readonly MatchingCoordinator $matching, private readonly AuditLogger $audit) {}

    /**
     * @param  array{skill_id: int, title: string, description?: string|null, amount: int, scheduled_at: string, duration_minutes?: int, payment_method?: string, modality: string, province: string, municipality: string, neighborhood: string, latitude: float, longitude: float}  $data
     */
    public function handle(User $student, array $data, ?TrainingRequest $republishedFrom = null): TrainingRequest
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);

        $request = DB::transaction(function () use ($student, $data, $scheduledAt, $republishedFrom) {
            // Serializa os pedidos do mesmo aluno para os limites não serem contornados em paralelo
            User::query()->whereKey($student->id)->lockForUpdate()->first();

            $this->ensureWithinActiveLimit($student);
            $this->ensureNotDuplicate($student, (int) $data['skill_id'], $scheduledAt);

            $split = Commission::split((int) $data['amount']);

            $request = new TrainingRequest([
                ...$data,
                'scheduled_at' => $scheduledAt,
                'commission' => $split['commission'],
                'trainer_net' => $split['trainer_net'],
                'republished_from_id' => $republishedFrom?->id,
            ]);
            $request->student_id = $student->id;
            $request->save();

            return $request;
        });

        $this->audit->record('requests.created', $request, ['amount' => $request->amount, 'republished_from' => $republishedFrom?->id], $student->id);

        $this->matching->start($request);

        return $request->refresh();
    }

    private function ensureWithinActiveLimit(User $student): void
    {
        $max = (int) config('longa.requests.max_active_per_student');
        $active = TrainingRequest::query()
            ->where('student_id', $student->id)
            ->whereIn('state', array_map(fn (RequestState $s) => $s->value, RequestState::active()))
            ->count();

        if ($active >= $max) {
            throw new ApiException('too_many_active_requests', "Já tem {$active} pedido(s) à procura de formador. Aguarde ou cancele um deles.", 409);
        }
    }

    private function ensureNotDuplicate(User $student, int $skillId, Carbon $scheduledAt): void
    {
        $window = (int) config('longa.requests.duplicate_window_minutes');

        $duplicate = TrainingRequest::query()
            ->where('student_id', $student->id)
            ->where('skill_id', $skillId)
            ->whereIn('state', [RequestState::Searching->value, RequestState::Matched->value])
            ->whereBetween('scheduled_at', [$scheduledAt->copy()->subMinutes($window), $scheduledAt->copy()->addMinutes($window)])
            ->exists();

        if ($duplicate) {
            throw new ApiException('duplicate_request', 'Já tem um pedido igual para essa altura.', 409);
        }
    }
}
