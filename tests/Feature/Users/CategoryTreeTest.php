<?php

declare(strict_types=1);

use App\Modules\Users\Enums\FieldType;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategoryPrice;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Verification\Models\VerificationDocument;
use Illuminate\Database\QueryException;

/*
| Fase 1.3: árvore de categorias com herança de formulário, preço, modalidades e documentos.
*/

function categoryNode(string $name, ?Category $parent = null, array $attributes = []): Category
{
    return Category::query()->create(['name' => $name, 'slug' => Str::slug($parent?->slug ?? 'teste').'-'.Str::slug($name), 'parent_id' => $parent?->id, 'active' => true, ...$attributes]);
}

it('monta a árvore e o caminho de uma folha', function () {
    $casa = categoryNode('Casa');
    $clima = categoryNode('Climatização', $casa);
    $ac = categoryNode('Manutenção de ar condicionado', $clima);

    expect($ac->path())->toBe('Casa › Climatização › Manutenção de ar condicionado')
        ->and(array_map(fn (Category $c) => $c->name, $ac->ancestors()))->toBe(['Casa', 'Climatização'])
        ->and($ac->isLeaf())->toBeTrue()
        ->and($casa->isLeaf())->toBeFalse()
        ->and(Category::query()->roots()->pluck('name')->all())->toContain('Casa')->not->toContain('Climatização')
        ->and($casa->children->pluck('name')->all())->toBe(['Climatização']);
});

it('herda modo de preço, modalidades, preço e documentos do antepassado mais próximo', function () {
    $edu = categoryNode('Educação', null, ['pricing_mode' => PricingMode::HourlyPackage, 'modalities' => ['in_person', 'online'], 'required_documents' => ['certificado_habilitacoes']]);
    $aulas = categoryNode('Aulas e explicações', $edu, ['required_documents' => ['registo_criminal']]);
    CategoryPrice::query()->create(['category_id' => $edu->id, 'hourly_min' => 2000, 'hourly_max' => 15000]);

    $fresh = $aulas->fresh();
    expect($fresh?->effectiveModalities())->toBe(['in_person', 'online'])
        ->and($fresh?->effectivePrice()?->hourly_max)->toBe(15000)
        ->and($fresh?->effectiveRequiredDocuments())->toBe(['certificado_habilitacoes', 'registo_criminal'])
        ->and(categoryNode('Limpeza')->effectiveModalities())->toBe(['in_person']);
});

it('o formulário junta os campos da linha e a subcategoria pode substituir um campo herdado', function () {
    $casa = categoryNode('Casa');
    $ac = categoryNode('Ar condicionado', $casa);
    $casa->fields()->createMany([
        ['key' => 'descricao', 'label' => 'O que se passa?', 'type' => FieldType::Text, 'required' => true, 'sort_order' => 1],
        ['key' => 'fotos', 'label' => 'Fotos', 'type' => FieldType::Photos, 'sort_order' => 2],
    ]);
    $ac->fields()->createMany([
        ['key' => 'btu', 'label' => 'Potência (BTU)', 'type' => FieldType::Choice, 'options' => ['9000', '12000', '18000'], 'sort_order' => 1],
        ['key' => 'fotos', 'label' => 'Fotos do aparelho', 'type' => FieldType::Photos, 'required' => true, 'sort_order' => 2],
    ]);

    $form = $ac->fresh()?->formFields() ?? [];
    expect(array_map(fn ($f) => $f->key, $form))->toBe(['descricao', 'fotos', 'btu'])
        ->and($form[1]->label)->toBe('Fotos do aparelho')
        ->and($form[1]->required)->toBeTrue()
        ->and($form[2]->options)->toBe(['9000', '12000', '18000']);
});

it('não deixa criar ciclos na árvore', function () {
    $a = categoryNode('A');
    $b = categoryNode('B', $a);
    $c = categoryNode('C', $b);

    expect($a->canHaveParent($c))->toBeFalse()
        ->and($a->canHaveParent($a))->toBeFalse()
        ->and($c->canHaveParent($a))->toBeTrue()
        ->and($b->canHaveParent(null))->toBeTrue();
});

it('não apaga uma categoria que ainda tem subcategorias', function () {
    $casa = categoryNode('Casa');
    categoryNode('Limpeza', $casa);

    expect(fn () => $casa->delete())->toThrow(QueryException::class);
});

it('o intervalo de referência sinaliza valores fora do normal', function () {
    $price = new CategoryPrice(['reference_min' => 10000, 'reference_max' => 50000]);

    expect($price->isOutsideReference(9999))->toBeTrue()
        ->and($price->isOutsideReference(10000))->toBeFalse()
        ->and($price->isOutsideReference(50001))->toBeTrue()
        ->and((new CategoryPrice)->isOutsideReference(1))->toBeFalse();
});

it('o técnico tem zonas, documentos, última localização e reputação', function () {
    $profile = ProviderProfile::factory()->create();
    $profile->zones()->create(['province' => 'Luanda', 'municipality' => 'Talatona', 'latitude' => -8.9167, 'longitude' => 13.1833, 'radius_m' => 8000]);
    $profile->documents()->create(['type' => 'certificado_profissional', 'file_path' => 'docs/x.pdf']);
    $profile->recordLocation(-8.8383, 13.2344);

    $fresh = $profile->fresh();
    expect($fresh?->zones)->toHaveCount(1)
        ->and($fresh?->documents->first()?->status)->toBe(VerificationDocument::PENDING)
        ->and($fresh?->last_latitude)->toBe(-8.8383)
        ->and($fresh?->last_location_at)->not->toBeNull()
        ->and($fresh?->rating_average)->toBe(0.0)
        ->and($fresh?->rating_count)->toBe(0);
});

it('tem os estados de verificação pedidos (pendente, aprovado, rejeitado, suspenso, removido)', function () {
    expect(array_map(fn (VerificationStatus $s) => $s->label(), VerificationStatus::cases()))
        ->toContain('Pendente', 'Aprovado', 'Rejeitado', 'Suspenso', 'Removido');
});
