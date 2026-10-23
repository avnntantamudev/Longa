<?php

declare(strict_types=1);

namespace App\Modules\Score\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Eventos de Score (recusas, ofertas sem resposta…). Nesta fase só se registam e mostram;
 * o cálculo da pontuação fica para a Fase 6.
 *
 * @tags Score
 */
class ScoreEventController extends Controller
{
    /**
     * Os meus eventos de score (formador).
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->trainerProfile ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de formador.', 409);

        return $this->respond($profile);
    }

    /**
     * Admin: eventos de score de um formador.
     */
    public function forTrainer(TrainerProfile $trainer): JsonResponse
    {
        return $this->respond($trainer);
    }

    private function respond(TrainerProfile $profile): JsonResponse
    {
        $query = fn (): Builder => ScoreEvent::query()->where('trainer_profile_id', $profile->id);

        $since = now()->subDays(30);
        $summary = $query()->where('created_at', '>=', $since)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $events = $query()->latest('id')->limit(50)->get()->map(fn (ScoreEvent $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'points' => $e->points,
            'metadata' => $e->metadata,
            'created_at' => $e->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $events,
            'summary_30_days' => $summary,
            'score' => $profile->score,
        ]);
    }
}
