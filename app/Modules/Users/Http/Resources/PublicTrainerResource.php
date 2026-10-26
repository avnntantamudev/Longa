<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Models\TrainerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil público do formador: sem telefone, sem e-mail e sem coordenadas exatas
 * (só município e bairro).
 *
 * @mixin TrainerProfile
 */
class PublicTrainerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            ...$this->user->photoUrls(),
            'bio' => $this->bio,
            'years_experience' => $this->years_experience,
            'modalities' => $this->modalities ?? [],
            'rate_amount' => $this->rate_amount,
            'rate_unit' => $this->rate_unit,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'service_radius_m' => $this->service_radius_m,
            'verified' => $this->isVerified(),
            'score' => $this->score,
            'badges' => $this->badges ?? [],
            'skills' => SkillResource::collection($this->whenLoaded('skills')),
        ];
    }
}
