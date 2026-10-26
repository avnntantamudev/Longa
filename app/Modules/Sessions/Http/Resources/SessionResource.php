<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\AvailableActions;
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
            'state_label' => $this->state->label(),
            'actions' => app(AvailableActions::class)->for($this->resource, $isTrainer),
            'request_id' => $this->training_request_id,
            'title' => $this->title,
            'skill' => $this->skill->name,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'modality' => $this->modality,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'duration_minutes' => $this->duration_minutes,
            'ends_at' => $this->ends_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmation_deadline' => $this->awaiting_since?->copy()->addHours((int) config('longa.session_rules.confirmation_window_hours'))->toIso8601String(),
            'no_show_contest_until' => $this->no_show_contest_until?->toIso8601String(),
            'cash_confirmed_at' => $this->cash_confirmed_at?->toIso8601String(),
            'amount' => $this->amount,
            'commission' => $this->when($isTrainer, $this->commission),
            'trainer_net' => $this->when($isTrainer, $this->trainer_net),
            'role' => $isTrainer ? 'trainer' : 'student',
            'conversation_id' => $this->conversation?->id,
            'student' => $this->when($isTrainer, fn () => ['name' => $this->student->name, ...$this->student->photoUrls()]),
            'trainer' => $this->when(! $isTrainer, fn () => [
                'id' => $this->trainer->id,
                'name' => $this->trainer->user->name,
                ...$this->trainer->user->photoUrls(),
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
