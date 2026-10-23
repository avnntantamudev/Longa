<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRequest>
 */
class TrainingRequestFactory extends Factory
{
    protected $model = TrainingRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = 25000;
        $split = Commission::split($amount);

        return [
            'student_id' => User::factory()->withRole(Role::Student),
            'skill_id' => fn () => Skill::query()->value('id'),
            'title' => 'Aula de SGME',
            'description' => 'Rever o módulo de controlo e monitorização.',
            'amount' => $amount,
            'commission' => $split['commission'],
            'trainer_net' => $split['trainer_net'],
            'scheduled_at' => now()->addDay()->setTime(14, 0),
            'modality' => 'in_person',
            'municipality' => 'Talatona',
            'neighborhood' => 'Talatona',
            'latitude' => TrainerProfileFactory::TALATONA[0],
            'longitude' => TrainerProfileFactory::TALATONA[1],
        ];
    }

    public function forSkill(Skill $skill): static
    {
        return $this->state(fn () => ['skill_id' => $skill->id]);
    }
}
