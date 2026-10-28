<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Admin\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Admin\Filament\Resources\Categories\Pages\ListCategories;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Seeders\CategoriesSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Fase 1.4: árvore inicial de categorias, API do catálogo e gestão no backoffice.
*/

function categoryBySlug(string $slug): Category
{
    return Category::query()->where('slug', $slug)->firstOrFail();
}

it('o seed cria a árvore pedida com modos de preço, modalidades e formulários', function () {
    $lessons = categoryBySlug('aulas-e-explicacoes');
    expect($lessons->path())->toBe('Educação › Aulas e explicações')
        ->and($lessons->pricing_mode)->toBe(PricingMode::HourlyPackage)
        ->and($lessons->effectiveModalities())->toBe(['in_person', 'online'])
        ->and(array_map(fn ($f) => $f->key, $lessons->formFields()))->toBe(['disciplina', 'nivel', 'horario_preferido']);

    $ac = categoryBySlug('manutencao-de-ar-condicionado');
    expect($ac->path())->toBe('Casa › Climatização › Manutenção de ar condicionado')
        ->and($ac->pricing_mode)->toBe(PricingMode::Quote)
        ->and($ac->effectiveModalities())->toBe(['in_person'])
        ->and($ac->effectivePrice()?->visit_fee)->toBe(5000);

    expect(categoryBySlug('electricidade')->path())->toBe('Casa › Electricidade')
        ->and(categoryBySlug('canalizacao')->pricing_mode)->toBe(PricingMode::Quote)
        ->and(categoryBySlug('limpeza')->pricing_mode)->toBe(PricingMode::Fixed)
        ->and(categoryBySlug('limpeza')->effectivePrice()?->fixed_price)->toBe(20000);
});

it('as áreas antigas ficam dentro de Educação, com os mesmos slugs, e os técnicos continuam ligados', function () {
    $math = categoryBySlug('matematica');
    $profile = ProviderProfile::factory()->create();
    $profile->categories()->attach($math);

    (new CategoriesSeeder)->run(); // idempotente: corre de novo sem duplicar nem desligar

    expect(categoryBySlug('matematica')->path())->toBe('Educação › Aulas e explicações › Explicações escolares › Matemática')
        ->and(Category::query()->where('slug', 'matematica')->count())->toBe(1)
        ->and($profile->fresh()?->categories->pluck('slug')->all())->toBe(['matematica'])
        ->and(categoryBySlug('matematica')->pricing_mode)->toBe(PricingMode::HourlyPackage);
});

it('a API lista só as folhas, pela ordem da árvore, com caminho e grupo', function () {
    $data = $this->getJson('/api/v1/categories')->assertOk()->json('data');
    $paths = array_column($data, 'path');

    expect($paths)->toContain('Casa › Limpeza', 'Casa › Climatização › Manutenção de ar condicionado')
        ->not->toContain('Casa', 'Educação › Aulas e explicações')
        ->and($paths[0])->toStartWith('Educação ›')
        ->and(end($paths))->toBe('Casa › Limpeza');

    $limpeza = collect($data)->firstWhere('slug', 'limpeza');
    expect($limpeza)->toMatchArray(['group' => 'Casa', 'pricing_mode' => 'fixed', 'modalities' => ['in_person']]);
});

it('a API devolve a árvore e o formulário com os campos herdados', function () {
    $tree = $this->getJson('/api/v1/categories/tree')->assertOk()->json('data');
    $casa = collect($tree)->firstWhere('slug', 'casa');
    expect(array_column($casa['children'], 'name'))->toBe(['Climatização', 'Electricidade', 'Canalização', 'Limpeza'])
        ->and($casa['children'][0]['children'][0]['name'])->toBe('Manutenção de ar condicionado');

    $form = $this->getJson('/api/v1/categories/'.categoryBySlug('manutencao-de-ar-condicionado')->id.'/form')->assertOk()->json('data');
    expect(array_column($form['fields'], 'key'))->toBe(['descricao', 'fotos', 'aparelhos', 'potencia'])
        ->and($form['fields'][1]['label'])->toBe('Fotos do aparelho')
        ->and($form['price'])->toMatchArray(['reference_min' => 10000, 'reference_max' => 80000, 'visit_fee' => 5000]);

    $this->getJson('/api/v1/categories/'.categoryBySlug('casa')->id.'/form')->assertNotFound();
});

it('uma categoria desativada sai da lista com as subcategorias', function () {
    categoryBySlug('climatizacao')->update(['active' => false]);

    $paths = array_column($this->getJson('/api/v1/categories')->json('data'), 'path');
    expect($paths)->not->toContain('Casa › Climatização › Manutenção de ar condicionado');
});

it('o admin gere categorias, preços e campos no backoffice; ciclos são recusados', function () {
    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Admin)->create());
    $casa = categoryBySlug('casa');

    Livewire::test(ListCategories::class)->assertOk()->searchTable('Casa')->assertCanSeeTableRecords([$casa]);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Jardinagem', 'slug' => 'jardinagem', 'parent_id' => $casa->id, 'pricing_mode' => 'fixed', 'active' => true])
        ->set('data.price.fixed_price', 12000)
        ->call('create')
        ->assertHasNoFormErrors();

    $garden = categoryBySlug('jardinagem');
    expect($garden->path())->toBe('Casa › Jardinagem')
        ->and($garden->price?->fixed_price)->toBe(12000);

    Livewire::test(EditCategory::class, ['record' => $casa->getRouteKey()])
        ->fillForm(['parent_id' => $garden->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);
    expect($casa->fresh()?->parent_id)->toBeNull();
});

it('o operador não gere categorias (inclui preços)', function () {
    Filament::setCurrentPanel('backoffice');
    loginWeb(User::factory()->withRole(Role::Operator)->create());

    $this->get(ListCategories::getUrl())->assertForbidden();
});
