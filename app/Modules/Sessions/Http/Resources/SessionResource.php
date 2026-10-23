<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use App\Modules\Sessions\Models\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aula vista por um dos participantes. Depois da aceitação:
 *  - o aluno vê os dados públicos completos do formador (sem contactos: a comunicação é pelo chat);
 *  - o formador vê o nome do aluno e o local exato.
 * Os valores mostram ao formador quanto recebe (valor − comissão).
 *
 * @mixin TrainingSession
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getAuthIdentifier();
        $isTrainer = $this->trainer->user_id === $viewerId;

        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'request_id' => $this->training_request_id,
            'title' => $this->title,
            'skill' => $this->skill->name,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'modality' => $this->modality,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'amount' => $this->amount,
            'commission' => $this->when($isTrainer, $this->commission),
            'trainer_net' => $this->when($isTrainer, $this->trainer_net),
            'role' => $isTrainer ? 'trainer' : 'student',
            'conversation_id' => $this->conversation?->id,
            'student' => $this->when($isTrainer, fn () => ['name' => $this->student->name]),
            'trainer' => $this->when(! $isTrainer, fn () => [
                'id' => $this->trainer->id,
                'name' => $this->trainer->user->name,
                'bio' => $this->trainer->bio,
                'years_experience' => $this->trainer->years_experience,
                'neighborhood' => $this->trainer->neighborhood,
                'verified' => $this->trainer->isVerified(),
                'score' => $this->trainer->score,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
