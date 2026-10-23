<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Users\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Áreas de formação iniciais (alinhadas com o kit: SGME, Primavera, Excel, disciplinas escolares).
 */
class SkillsSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    public const SKILLS = [
        'Gestão e contabilidade' => ['SGME — Gestão Empresarial', 'Primavera — ERP e Contabilidade', 'Contabilidade', 'Gestão de Recursos Humanos'],
        'Informática' => ['Excel — Avançado para Finanças', 'Word e PowerPoint', 'Programação', 'Informática na ótica do utilizador'],
        'Explicações escolares' => ['Matemática', 'Física', 'Química', 'Biologia', 'Português', 'História', 'Geografia'],
        'Línguas' => ['Inglês', 'Francês'],
    ];

    public function run(): void
    {
        foreach (self::SKILLS as $category => $names) {
            foreach ($names as $name) {
                Skill::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'category' => $category, 'active' => true]);
            }
        }
    }
}
