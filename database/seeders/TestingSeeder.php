<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Dados base para os testes (corre uma vez por execução, com RefreshDatabase).
 */
class TestingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesSeeder::class, SkillsSeeder::class]);
    }
}
