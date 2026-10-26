<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\SkillSuggestions\Pages\ListSkillSuggestions;
use App\Modules\Notifications\Notifications\SkillSuggestionReviewedNotification;
use App\Modules\Users\Actions\SkillSuggestions;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\SkillSuggestion;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

function suggestAs(TrainerProfile $trainer, string $name): Illuminate\Testing\TestResponse
{
    apiLogin($trainer->user);

    return test()->postJson('/api/v1/me/trainer-profile/skill-suggestions', ['name' => $name]);
}

it('o formador sugere uma área: fica em análise e não entra na lista', function () {
    $trainer = TrainerProfile::factory()->create();

    suggestAs($trainer, '  Contabilidade   no Primavera ')->assertCreated()
        ->assertJsonPath('data.name', 'Contabilidade no Primavera')
        ->assertJsonPath('data.status', 'pending');
    suggestAs($trainer, 'contabilidade no primavera')->assertOk(); // a mesma não duplica

    expect(Skill::query()->where('slug', 'contabilidade-no-primavera')->exists())->toBeFalse()
        ->and($this->getJson('/api/v1/skills')->json('data.*.name'))->not->toContain('Contabilidade no Primavera');
    $this->getJson('/api/v1/me/trainer-profile/skill-suggestions')->assertJsonCount(1, 'data');
});

it('se a área já existe na lista, é ligada logo ao perfil', function () {
    $trainer = TrainerProfile::factory()->create();
    $skill = Skill::query()->firstOrFail();

    suggestAs($trainer, $skill->name)->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.skill.id', $skill->id);

    expect($trainer->skills()->whereKey($skill->id)->exists())->toBeTrue();
});

it('aprovar como área nova liga-a a todos os formadores que escreveram o mesmo e avisa-os', function () {
    $a = TrainerProfile::factory()->create(['onboarding_completed_at' => null]);
    $b = TrainerProfile::factory()->create();
    suggestAs($a, 'Contabilidade no Primavera')->assertCreated();
    suggestAs($b, 'contabilidade no primavera!')->assertCreated();

    $count = app(SkillSuggestions::class)->approveAsNew(SkillSuggestion::query()->firstOrFail(), User::factory()->withRole(Role::Admin)->create(), 'Primavera — Contabilidade', 'Gestão');

    $skill = Skill::query()->where('name', 'Primavera — Contabilidade')->sole();
    expect($count)->toBe(2)
        ->and($a->skills()->whereKey($skill->id)->exists())->toBeTrue()
        ->and($b->skills()->whereKey($skill->id)->exists())->toBeTrue()
        ->and($a->fresh()?->onboarding_completed_at)->not->toBeNull() // a área era o que faltava
        ->and(SkillSuggestion::query()->where('status', 'approved')->count())->toBe(2);
    Notification::assertSentTo([$a->user, $b->user], SkillSuggestionReviewedNotification::class);
});

it('juntar a uma área existente, rejeitar com motivo, e não decidir duas vezes', function () {
    $trainer = TrainerProfile::factory()->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $excel = Skill::query()->firstOrFail();
    suggestAs($trainer, 'Excel muito avançado')->assertCreated();
    suggestAs($trainer, 'Tarot')->assertCreated();
    [$first, $second] = SkillSuggestion::query()->orderBy('id')->get()->all();

    app(SkillSuggestions::class)->approveAsExisting($first, $admin, $excel);
    app(SkillSuggestions::class)->reject($second, $admin, 'Não é uma área de formação profissional');

    expect($trainer->skills()->whereKey($excel->id)->exists())->toBeTrue()
        ->and($second->fresh()?->status)->toBe('rejected');
    expect(fn () => app(SkillSuggestions::class)->reject($first, $admin, 'Outra vez'))->toThrow(App\Support\Api\ApiException::class);
});

it('limite de 5 áreas em análise por formador e só formadores sugerem', function () {
    $trainer = TrainerProfile::factory()->create();
    foreach (['Área um', 'Área dois', 'Área três', 'Área quatro', 'Área cinco'] as $name) {
        suggestAs($trainer, $name)->assertCreated();
    }
    suggestAs($trainer, 'Área seis')->assertStatus(409)->assertJsonPath('error.code', 'too_many_suggestions');

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->postJson('/api/v1/me/trainer-profile/skill-suggestions', ['name' => 'Xadrez'])->assertStatus(409)->assertJsonPath('error.code', 'profile_missing');
});

it('o admin aprova no backoffice criando a área', function () {
    Filament::setCurrentPanel('backoffice');
    $trainer = TrainerProfile::factory()->create();
    suggestAs($trainer, 'Contabilidade no Primavera')->assertCreated();
    $suggestion = SkillSuggestion::query()->sole();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ListSkillSuggestions::class)
        ->assertCanSeeTableRecords([$suggestion])
        ->callTableAction('approve', $suggestion, data: ['mode' => 'new', 'name' => 'Primavera — Contabilidade', 'category' => $category = Skill::query()->value('category')])
        ->assertHasNoTableActionErrors();

    expect($suggestion->fresh()?->status)->toBe('approved')
        ->and(Skill::query()->where('name', 'Primavera — Contabilidade')->value('category'))->toBe($category);
});
