<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '+2449'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'name' => fake()->name(),
            'email' => null,
            'locale' => 'pt',
            'onboarded_at' => now(),
        ];
    }

    public function notOnboarded(): static
    {
        return $this->state(fn () => ['name' => null, 'onboarded_at' => null]);
    }

    public function suspended(): static
    {
        return $this->afterMaking(fn (User $user) => $user->status = UserStatus::Suspended);
    }

    public function withRole(Role ...$roles): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(array_map(fn (Role $r) => $r->value, $roles)));
    }
}
