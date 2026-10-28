<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\Consent;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\LegalDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            // Último dígito nunca 0: telefones terminados em 0000 são recusados pelo gateway simulado
            'phone' => '+2449'.fake()->unique()->numerify('#######').fake()->numberBetween(1, 9),
            'phone_verified_at' => now(),
            'name' => fake()->name(),
            'email' => null,
            'locale' => 'pt',
            'onboarded_at' => now(),
        ];
    }

    /**
     * Com foto registada (só o nome; os ficheiros não existem nos testes).
     */
    /**
     * Quem concluiu o registo aceitou os Termos e a Política em vigor (como no onboarding real).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->onboarded_at === null) {
                return;
            }
            $legal = app(LegalDocuments::class);
            foreach (array_keys(LegalDocuments::DOCUMENTS) as $document) {
                Consent::query()->forceCreate(['user_id' => $user->id, 'type' => $document, 'version' => $legal->currentVersion($document), 'accepted_at' => now()]);
            }
        });
    }

    public function withPhoto(): static
    {
        return $this->state(fn () => ['photo' => strtolower(Str::random(32)), 'photo_updated_at' => now()]);
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
