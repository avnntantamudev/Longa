<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Users\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Áreas de formação iniciais (alinhadas com o kit: SGME, Primavera, Excel, disciplinas escolares).
 */
class CategoriesSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    public const CATEGORIES = [
        'Gestão e contabilidade' => ['SGME — Gestão Empresarial', 'Primavera — ERP e Contabilidade', 'Contabilidade', 'Gestão de Recursos Humanos'],
        'Informática' => ['Excel — Avançado para Finanças', 'Word e PowerPoint', 'Programação', 'Informática na ótica do utilizador'],
        'Explicações escolares' => ['Matemática', 'Física', 'Química', 'Biologia', 'Português', 'História', 'Geografia'],
        'Línguas' => ['Inglês', 'Francês'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $area => $names) {
            foreach ($names as $name) {
                Category::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'area' => $area, 'active' => true]);
            }
        }
    }
}
