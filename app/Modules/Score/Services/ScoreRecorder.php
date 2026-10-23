<?php

declare(strict_types=1);

namespace App\Modules\Score\Services;

use App\Modules\Score\Models\ScoreEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Regista eventos que vão influenciar o Score. Nesta fase os pontos ficam a 0 (cálculo na Fase 6).
 */
class ScoreRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(int $trainerProfileId, string $type, ?Model $subject = null, array $metadata = []): ScoreEvent
    {
        return ScoreEvent::query()->create([
            'trainer_profile_id' => $trainerProfileId,
            'type' => $type,
            'points' => 0,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
