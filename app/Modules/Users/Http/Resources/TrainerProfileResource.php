<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Models\TrainerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil de formador visto pelo próprio (inclui coordenadas e estado do onboarding).
 * Para terceiros usar PublicTrainerResource.
 *
 * @mixin TrainerProfile
 */
class TrainerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bio' => $this->bio,
            'years_experience' => $this->years_experience,
            'modalities' => $this->modalities ?? [],
            'rate_amount' => $this->rate_amount,
            'rate_unit' => $this->rate_unit,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'service_radius_m' => $this->service_radius_m,
            'verification_status' => $this->verification_status->value,
            'score' => $this->score,
            'badges' => $this->badges ?? [],
            'is_available' => $this->is_available,
            'skills' => SkillResource::collection($this->whenLoaded('skills')),
            'onboarding' => [
                'photo' => $this->user->photo !== null,
                'details' => filled($this->bio),
                'skills' => $this->relationLoaded('skills') ? $this->skills->isNotEmpty() : null,
                'location' => $this->hasLocation(),
                'completed' => $this->onboarding_completed_at !== null,
            ],
        ];
    }
}
