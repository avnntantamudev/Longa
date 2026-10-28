<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\CategorySuggestions\Pages\ListCategorySuggestions;
use App\Modules\Notifications\Notifications\CategorySuggestionReviewedNotification;
use App\Modules\Users\Actions\CategorySuggestions;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategorySuggestion;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

function suggestAs(ProviderProfile $provider, string $name): Illuminate\Testing\TestResponse
{
    apiLogin($provider->user);

    return test()->postJson('/api/v1/me/provider-profile/category-suggestions', ['name' => $name]);
}

it('o formador sugere uma área: fica em análise e não entra na lista', function () {
    $provider = ProviderProfile::factory()->create();

    suggestAs($provider, '  Contabilidade   no Primavera ')->assertCreated()
        ->assertJsonPath('data.name', 'Contabilidade no Primavera')
        ->assertJsonPath('data.status', 'pending');
    suggestAs($provider, 'contabilidade no primavera')->assertOk(); // a mesma não duplica

    expect(Category::query()->where('slug', 'contabilidade-no-primavera')->exists())->toBeFalse()
        ->and($this->getJson('/api/v1/categories')->json('data.*.name'))->not->toContain('Contabilidade no Primavera');
    $this->getJson('/api/v1/me/provider-profile/category-suggestions')->assertJsonCount(1, 'data');
});

it('se a área já existe na lista, é ligada logo ao perfil', function () {
    $provider = ProviderProfile::factory()->create();
    $category = Category::query()->firstOrFail();

    suggestAs($provider, $category->name)->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.category.id', $category->id);

    expect($provider->categories()->whereKey($category->id)->exists())->toBeTrue();
});

it('aprovar como área nova liga-a a todos os formadores que escreveram o mesmo e avisa-os', function () {
    $a = ProviderProfile::factory()->create(['onboarding_completed_at' => null]);
    $b = ProviderProfile::factory()->create();
    suggestAs($a, 'Contabilidade no Primavera')->assertCreated();
    suggestAs($b, 'contabilidade no primavera!')->assertCreated();

    $count = app(CategorySuggestions::class)->approveAsNew(CategorySuggestion::query()->firstOrFail(), User::factory()->withRole(Role::Admin)->create(), 'Primavera — Contabilidade', 'Gestão');

    $category = Category::query()->where('name', 'Primavera — Contabilidade')->sole();
    expect($count)->toBe(2)
        ->and($a->categories()->whereKey($category->id)->exists())->toBeTrue()
        ->and($b->categories()->whereKey($category->id)->exists())->toBeTrue()
        ->and($a->fresh()?->onboarding_completed_at)->not->toBeNull() // a área era o que faltava
        ->and(CategorySuggestion::query()->where('status', 'approved')->count())->toBe(2);
    Notification::assertSentTo([$a->user, $b->user], CategorySuggestionReviewedNotification::class);
});

it('juntar a uma área existente, rejeitar com motivo, e não decidir duas vezes', function () {
    $provider = ProviderProfile::factory()->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $excel = Category::query()->firstOrFail();
    suggestAs($provider, 'Excel muito avançado')->assertCreated();
    suggestAs($provider, 'Tarot')->assertCreated();
    [$first, $second] = CategorySuggestion::query()->orderBy('id')->get()->all();

    app(CategorySuggestions::class)->approveAsExisting($first, $admin, $excel);
    app(CategorySuggestions::class)->reject($second, $admin, 'Não é uma área de formação profissional');

    expect($provider->categories()->whereKey($excel->id)->exists())->toBeTrue()
        ->and($second->fresh()?->status)->toBe('rejected');
    expect(fn () => app(CategorySuggestions::class)->reject($first, $admin, 'Outra vez'))->toThrow(App\Support\Api\ApiException::class);
});

it('limite de 5 áreas em análise por formador e só formadores sugerem', function () {
    $provider = ProviderProfile::factory()->create();
    foreach (['Área um', 'Área dois', 'Área três', 'Área quatro', 'Área cinco'] as $name) {
        suggestAs($provider, $name)->assertCreated();
    }
    suggestAs($provider, 'Área seis')->assertStatus(409)->assertJsonPath('error.code', 'too_many_suggestions');

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->postJson('/api/v1/me/provider-profile/category-suggestions', ['name' => 'Xadrez'])->assertStatus(409)->assertJsonPath('error.code', 'profile_missing');
});

it('o admin aprova no backoffice criando a área', function () {
    Filament::setCurrentPanel('backoffice');
    $provider = ProviderProfile::factory()->create();
    suggestAs($provider, 'Contabilidade no Primavera')->assertCreated();
    $suggestion = CategorySuggestion::query()->sole();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ListCategorySuggestions::class)
        ->assertCanSeeTableRecords([$suggestion])
        ->callTableAction('approve', $suggestion, data: ['mode' => 'new', 'name' => 'Primavera — Contabilidade', 'area' => $area = Category::query()->value('area')])
        ->assertHasNoTableActionErrors();

    expect($suggestion->fresh()?->status)->toBe('approved')
        ->and(Category::query()->where('name', 'Primavera — Contabilidade')->value('area'))->toBe($area);
});
