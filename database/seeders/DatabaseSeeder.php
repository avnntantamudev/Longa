<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesSeeder::class, SkillsSeeder::class]);

        // Administrador de desenvolvimento: entra por OTP com este telefone (o código aparece em storage/logs)
        if (app()->isLocal()) {
            $admin = User::query()->firstOrCreate(
                ['phone' => '+244900000001'],
                ['name' => 'Admin LONGA', 'phone_verified_at' => now(), 'onboarded_at' => now()],
            );
            $admin->assignRole(Role::Admin->value);
        }
    }
}
