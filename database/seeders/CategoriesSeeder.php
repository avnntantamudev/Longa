<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Users\Enums\FieldType;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategoryField;
use App\Modules\Users\Models\CategoryPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Árvore inicial de categorias do BISCA (Fase 1.4). Idempotente: atualiza pelo slug, nunca apaga.
 *
 * As áreas de formação do LONGA (SGME, Primavera, Excel, disciplinas escolares, línguas) passam a subgrupos de
 * Educação › Aulas e explicações, com os mesmos slugs, por isso os técnicos que já as tinham continuam ligados.
 * Os preços são valores de partida: o admin muda-os no backoffice (Categorias).
 */
class CategoriesSeeder extends Seeder
{
    /** @var array<string, list<string>> áreas antigas → disciplinas (folhas) */
    public const LESSON_AREAS = [
        'Gestão e contabilidade' => ['SGME — Gestão Empresarial', 'Primavera — ERP e Contabilidade', 'Contabilidade', 'Gestão de Recursos Humanos'],
        'Informática' => ['Excel — Avançado para Finanças', 'Word e PowerPoint', 'Programação', 'Informática na ótica do utilizador'],
        'Explicações escolares' => ['Matemática', 'Física', 'Química', 'Biologia', 'Português', 'História', 'Geografia'],
        'Línguas' => ['Inglês', 'Francês'],
    ];

    private int $order = 0;

    public function run(): void
    {
        $inPerson = ['in_person'];

        // Educação › Aulas e explicações (por hora ou pacote, presencial e online)
        $edu = $this->node('educacao', 'Educação', null, PricingMode::HourlyPackage, ['in_person', 'online'], icon: 'academic-cap');
        $lessons = $this->node('aulas-e-explicacoes', 'Aulas e explicações', $edu, PricingMode::HourlyPackage, description: 'Explicações escolares, formação profissional e línguas.');
        $this->price($lessons, ['hourly_min' => 1500, 'hourly_max' => 20000]);
        $this->fields($lessons, [
            ['key' => 'disciplina', 'label' => 'Disciplina ou tema', 'type' => FieldType::Text, 'required' => true, 'help' => 'Ex.: Matemática da 10.ª classe, fórmulas de Excel.'],
            ['key' => 'nivel', 'label' => 'Nível', 'type' => FieldType::Choice, 'required' => true, 'options' => ['Ensino primário', 'I ciclo', 'II ciclo', 'Ensino superior', 'Profissional', 'Outro']],
            ['key' => 'horario_preferido', 'label' => 'Horário preferido', 'type' => FieldType::Text, 'help' => 'Ex.: dias úteis depois das 17h.'],
        ]);
        foreach (self::LESSON_AREAS as $area => $subjects) {
            $group = $this->node(Str::slug($area), $area, $lessons, PricingMode::HourlyPackage);
            foreach ($subjects as $subject) {
                $this->node(Str::slug($subject), $subject, $group, PricingMode::HourlyPackage);
            }
        }

        // Casa (presencial): descrição e fotos para todos os serviços da casa
        $home = $this->node('casa', 'Casa', null, PricingMode::Quote, $inPerson, icon: 'home');
        $this->fields($home, [
            ['key' => 'descricao', 'label' => 'O que precisa?', 'type' => FieldType::Text, 'required' => true, 'help' => 'Descreva o problema ou o trabalho.'],
            ['key' => 'fotos', 'label' => 'Fotos', 'type' => FieldType::Photos, 'help' => 'Ajudam o técnico a preparar a visita.'],
        ]);

        $climate = $this->node('climatizacao', 'Climatização', $home, PricingMode::Quote);
        $ac = $this->node('manutencao-de-ar-condicionado', 'Manutenção de ar condicionado', $climate, PricingMode::Quote, required: ['certificado_profissional']);
        $this->price($ac, ['reference_min' => 10000, 'reference_max' => 80000, 'visit_fee' => 5000]);
        $this->fields($ac, [
            ['key' => 'aparelhos', 'label' => 'Número de aparelhos', 'type' => FieldType::Number, 'required' => true],
            ['key' => 'potencia', 'label' => 'Potência (BTU)', 'type' => FieldType::Choice, 'options' => ['9000', '12000', '18000', '24000', 'Não sei']],
            ['key' => 'fotos', 'label' => 'Fotos do aparelho', 'type' => FieldType::Photos],
        ]);

        $electric = $this->node('electricidade', 'Electricidade', $home, PricingMode::Quote, required: ['certificado_profissional']);
        $this->price($electric, ['reference_min' => 8000, 'reference_max' => 150000, 'visit_fee' => 5000]);
        $this->fields($electric, [
            ['key' => 'tipo', 'label' => 'Tipo de trabalho', 'type' => FieldType::Choice, 'required' => true, 'options' => ['Avaria', 'Instalação nova', 'Quadro elétrico', 'Iluminação', 'Outro']],
        ]);

        $plumbing = $this->node('canalizacao', 'Canalização', $home, PricingMode::Quote);
        $this->price($plumbing, ['reference_min' => 8000, 'reference_max' => 120000, 'visit_fee' => 5000]);
        $this->fields($plumbing, [
            ['key' => 'tipo', 'label' => 'Tipo de trabalho', 'type' => FieldType::Choice, 'required' => true, 'options' => ['Fuga de água', 'Entupimento', 'Instalação', 'Outro']],
        ]);

        $cleaning = $this->node('limpeza', 'Limpeza', $home, PricingMode::Fixed, description: 'Limpeza doméstica com preço fechado por tipologia.');
        $this->price($cleaning, ['fixed_price' => 20000]);
        $this->fields($cleaning, [
            ['key' => 'tipologia', 'label' => 'Tipologia', 'type' => FieldType::Choice, 'required' => true, 'options' => ['T1', 'T2', 'T3', 'T4 ou maior']],
        ]);
    }

    /**
     * @param  list<string>|null  $modalities
     * @param  list<string>|null  $required
     */
    private function node(string $slug, string $name, ?Category $parent, PricingMode $mode, ?array $modalities = null, ?array $required = null, ?string $description = null, ?string $icon = null): Category
    {
        return Category::query()->updateOrCreate(['slug' => $slug], [
            'name' => $name, 'parent_id' => $parent?->id, 'pricing_mode' => $mode, 'modalities' => $modalities,
            'required_documents' => $required, 'description' => $description, 'icon' => $icon, 'area' => null,
            'sort_order' => ++$this->order, 'active' => true,
        ]);
    }

    /** @param array<string, int> $values */
    private function price(Category $category, array $values): void
    {
        CategoryPrice::query()->updateOrCreate(['category_id' => $category->id], $values);
    }

    /** @param list<array<string, mixed>> $fields */
    private function fields(Category $category, array $fields): void
    {
        foreach ($fields as $i => $field) {
            CategoryField::query()->updateOrCreate(['category_id' => $category->id, 'key' => $field['key']], [...$field, 'sort_order' => $i + 1]);
        }
    }
}
