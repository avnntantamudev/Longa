<?php

declare(strict_types=1);

use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Notifications\Notifications\PhotoRejectedNotification;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function candidatesFor(Category $category): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $request = ServiceRequest::factory()->create(['category_id' => $category->id, 'latitude' => $lat, 'longitude' => $lng, 'scheduled_at' => now()->addDay()]);

    return array_map(fn ($c) => $c->providerProfileId, app(ProviderMatcher::class)->candidates($request, 50));
}

it('técnico sem foto recebe pedidos até à data de transição e depois deixa de receber', function () {
    $category = Category::query()->firstOrFail();
    $withPhoto = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $withoutPhoto = ProviderProfile::factory()->verified()->withCategories($category)->create();
    $withoutPhoto->user->forceFill(['photo' => null])->save();

    config(['longa.providers.photo_required_from' => now()->addDays(7)->toDateTimeString()]);
    expect(candidatesFor($category))->toContain($withPhoto->id, $withoutPhoto->id);

    config(['longa.providers.photo_required_from' => now()->subMinute()->toDateTimeString()]);
    expect(candidatesFor($category))->toContain($withPhoto->id)->not->toContain($withoutPhoto->id);
});

it('a verificação do BI exige foto e o técnico não pode apagá-la', function () {
    $provider = ProviderProfile::factory()->create();
    $provider->user->forceFill(['photo' => null])->save();
    apiLogin($provider->user);

    $this->post('/api/v1/me/verifications', [
        'document_number' => '001234567LA042',
        'files' => [UploadedFile::fake()->image('bi.jpg')],
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'photo_required');

    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('eu.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();
    $this->deleteJson('/api/v1/me/photo')->assertStatus(409)->assertJsonPath('error.code', 'photo_required');
});

it('o admin remove uma foto imprópria e o utilizador é avisado', function () {
    Notification::fake();
    $provider = ProviderProfile::factory()->create();
    apiLogin($provider->user);
    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('eu.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();
    $photo = $provider->user->fresh()?->photo;

    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->postJson("/api/v1/admin/users/{$provider->user_id}/photo/reject", ['reason' => 'Não se vê o rosto'])->assertForbidden();

    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->postJson("/api/v1/admin/users/{$provider->user_id}/photo/reject", ['reason' => 'Não se vê o rosto'])->assertOk();

    expect($provider->user->fresh()?->photo)->toBeNull();
    Storage::disk('local')->assertMissing("photos/{$photo}-512.webp");
    Notification::assertSentTo($provider->user, PhotoRejectedNotification::class, fn ($n) => $n->reason === 'Não se vê o rosto');
    $this->postJson("/api/v1/admin/users/{$provider->user_id}/photo/reject", ['reason' => 'Outra vez'])->assertStatus(409);
});
