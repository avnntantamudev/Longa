<?php

declare(strict_types=1);

use App\Modules\Matching\Services\TrainerMatcher;
use App\Modules\Notifications\Notifications\PhotoRejectedNotification;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function candidatesFor(Skill $skill): array
{
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $request = TrainingRequest::factory()->create(['skill_id' => $skill->id, 'latitude' => $lat, 'longitude' => $lng, 'scheduled_at' => now()->addDay()]);

    return array_map(fn ($c) => $c->trainerProfileId, app(TrainerMatcher::class)->candidates($request, 50));
}

it('formador sem foto recebe pedidos até à data de transição e depois deixa de receber', function () {
    $skill = Skill::query()->firstOrFail();
    $withPhoto = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $withoutPhoto = TrainerProfile::factory()->verified()->withSkills($skill)->create();
    $withoutPhoto->user->forceFill(['photo' => null])->save();

    config(['longa.trainers.photo_required_from' => now()->addDays(7)->toDateTimeString()]);
    expect(candidatesFor($skill))->toContain($withPhoto->id, $withoutPhoto->id);

    config(['longa.trainers.photo_required_from' => now()->subMinute()->toDateTimeString()]);
    expect(candidatesFor($skill))->toContain($withPhoto->id)->not->toContain($withoutPhoto->id);
});

it('a verificação do BI exige foto e o formador não pode apagá-la', function () {
    $trainer = TrainerProfile::factory()->create();
    $trainer->user->forceFill(['photo' => null])->save();
    apiLogin($trainer->user);

    $this->post('/api/v1/me/verifications', [
        'document_number' => '001234567LA042',
        'files' => [UploadedFile::fake()->image('bi.jpg')],
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'photo_required');

    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('eu.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();
    $this->deleteJson('/api/v1/me/photo')->assertStatus(409)->assertJsonPath('error.code', 'photo_required');
});

it('o admin remove uma foto imprópria e o utilizador é avisado', function () {
    Notification::fake();
    $trainer = TrainerProfile::factory()->create();
    apiLogin($trainer->user);
    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('eu.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();
    $photo = $trainer->user->fresh()?->photo;

    apiLogin(User::factory()->withRole(Role::Student)->create());
    $this->postJson("/api/v1/admin/users/{$trainer->user_id}/photo/reject", ['reason' => 'Não se vê o rosto'])->assertForbidden();

    apiLogin(User::factory()->withRole(Role::Admin)->create());
    $this->postJson("/api/v1/admin/users/{$trainer->user_id}/photo/reject", ['reason' => 'Não se vê o rosto'])->assertOk();

    expect($trainer->user->fresh()?->photo)->toBeNull();
    Storage::disk('local')->assertMissing("photos/{$photo}-512.webp");
    Notification::assertSentTo($trainer->user, PhotoRejectedNotification::class, fn ($n) => $n->reason === 'Não se vê o rosto');
    $this->postJson("/api/v1/admin/users/{$trainer->user_id}/photo/reject", ['reason' => 'Outra vez'])->assertStatus(409);
});
