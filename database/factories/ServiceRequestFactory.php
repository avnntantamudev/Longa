<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    protected $model = ServiceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = 25000;
        $split = Commission::split($amount);

        return [
            'client_id' => User::factory()->withRole(Role::Client),
            'category_id' => fn () => Category::query()->value('id'),
            'title' => 'Aula de SGME',
            'description' => 'Rever o módulo de controlo e monitorização.',
            'amount' => $amount,
            'commission' => $split['commission'],
            'provider_net' => $split['provider_net'],
            'scheduled_at' => now()->addDay()->setTime(14, 0),
            'modality' => 'in_person',
            'municipality' => 'Talatona',
            'neighborhood' => 'Talatona',
            'latitude' => ProviderProfileFactory::TALATONA[0],
            'longitude' => ProviderProfileFactory::TALATONA[1],
        ];
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->id]);
    }
}
