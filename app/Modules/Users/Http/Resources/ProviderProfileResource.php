<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\ProviderProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Perfil de técnico visto pelo próprio (inclui coordenadas e estado do onboarding).
 * Para terceiros usar PublicProviderResource.
 *
 * @mixin ProviderProfile
 */
class ProviderProfileResource extends JsonResource
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
            'province' => $this->province,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'service_radius_m' => $this->service_radius_m,
            'verification_status' => $this->verification_status->value,
            'vip' => app(VipSubscriptions::class)->isVip($this->resource),
            'score' => $this->score,
            'badges' => $this->badges ?? [],
            'is_available' => $this->is_available,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            /** Preço por hora por categoria (categorias por hora/pacote): {"12": 5000} */
            'hourly_rates' => $this->whenLoaded('categories', fn () => (object) DB::table('category_provider_profile')
                ->where('provider_profile_id', $this->id)->whereNotNull('hourly_rate')
                ->pluck('hourly_rate', 'category_id')->mapWithKeys(fn ($rate, $id) => [(string) $id => (int) $rate])->all()),
            'onboarding' => [
                'photo' => $this->user->photo !== null,
                'details' => filled($this->bio),
                'categories' => $this->relationLoaded('categories') ? $this->categories->isNotEmpty() : null,
                'location' => $this->hasLocation(),
                'completed' => $this->onboarding_completed_at !== null,
            ],
        ];
    }
}
