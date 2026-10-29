<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderProfile>
 */
class ProviderProfileFactory extends Factory
{
    protected $model = ProviderProfile::class;

    /** Talatona, Luanda (latitude, longitude) */
    public const TALATONA = [-8.9167, 13.1833];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withRole(Role::Provider)->withPhoto(),
            'bio' => fake()->sentence(12),
            'years_experience' => fake()->numberBetween(1, 15),
            'modalities' => ['in_person'],
            'rate_amount' => fake()->randomElement([5000, 10000, 15000, 25000]),
            'rate_unit' => 'session',
            'municipality' => 'Belas',
            'neighborhood' => 'Talatona',
            'latitude' => self::TALATONA[0],
            'longitude' => self::TALATONA[1],
            'service_radius_m' => 5000,
            'is_available' => true,
            'onboarding_completed_at' => now(),
        ];
    }

    public function verified(int $score = 50): static
    {
        return $this->afterMaking(function (ProviderProfile $profile) use ($score) {
            $profile->verification_status = VerificationStatus::Verified;
            $profile->score = $score;
        });
    }

    /**
     * Coloca o formador a uma distância (metros) para norte de um ponto.
     */
    public function near(float $latitude, float $longitude, int $metersNorth = 0): static
    {
        return $this->state(fn () => [
            'latitude' => $latitude + $metersNorth / 111_320,
            'longitude' => $longitude,
        ]);
    }

    /** Preço por hora usado nas categorias por hora/pacote (ex.: 2 h = 25.000 Kz) */
    public const HOURLY_RATE = 12500;

    public function withCategories(Category ...$categories): static
    {
        return $this->afterCreating(fn (ProviderProfile $profile) => $profile->categories()->attach(
            array_fill_keys(array_map(fn (Category $s) => $s->id, $categories), ['hourly_rate' => self::HOURLY_RATE]),
        ));
    }
}
