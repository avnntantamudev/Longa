<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Modules\Verification\Models\Verification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function submitBi(string $number = '001234567LA042'): Illuminate\Testing\TestResponse
{
    return test()->post('/api/v1/me/verifications', [
        'document_number' => $number,
        'files' => [UploadedFile::fake()->image('bi-frente.jpg'), UploadedFile::fake()->create('bi-verso.pdf', 200, 'application/pdf')],
    ], ['Accept' => 'application/json']);
}

beforeEach(fn () => Storage::fake('local'));

it('submete o BI, guarda os ficheiros em privado e nunca devolve o número completo', function () {
    $profile = TrainerProfile::factory()->create();
    apiLogin($profile->user);

    $response = submitBi()->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.document_number_masked', '•••••••••LA042')
        ->assertJsonPath('data.documents_count', 2);

    expect($response->getContent())->not->toContain('001234567LA042')
        ->and($profile->fresh()?->verification_status->value)->toBe('pending');

    $paths = Verification::query()->sole()->documents ?? [];
    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

it('valida o formato do BI', function () {
    apiLogin(TrainerProfile::factory()->create()->user);

    submitBi('12345')->assertStatus(422)->assertJsonValidationErrors(['document_number'], 'error.details');
});

it('impede o mesmo BI em duas contas', function () {
    apiLogin(TrainerProfile::factory()->create()->user);
    submitBi()->assertCreated();

    apiLogin(TrainerProfile::factory()->create()->user);
    submitBi('001234567la042')->assertStatus(409)->assertJsonPath('error.code', 'document_already_used');
});

it('só formadores submetem verificação', function () {
    apiLogin(User::factory()->withRole(Role::Student)->create());

    submitBi()->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('o admin aprova e o formador fica verificado', function () {
    $profile = TrainerProfile::factory()->create();
    apiLogin($profile->user);
    $id = submitBi()->json('data.id');

    apiLogin(User::factory()->withRole(Role::Admin)->create());

    $this->getJson('/api/v1/admin/verifications')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/admin/verifications/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/admin/verifications/{$id}/approve")->assertStatus(409);

    expect($profile->fresh()?->verification_status->value)->toBe('verified')
        ->and(AuditLog::query()->where('action', 'verification.approved')->exists())->toBeTrue();
});

it('o admin rejeita com motivo', function () {
    $profile = TrainerProfile::factory()->create();
    apiLogin($profile->user);
    $id = submitBi()->json('data.id');

    apiLogin(User::factory()->withRole(Role::Admin)->create());

    $this->postJson("/api/v1/admin/verifications/{$id}/reject")->assertStatus(422);
    $this->postJson("/api/v1/admin/verifications/{$id}/reject", ['reason' => 'Fotografia ilegível'])
        ->assertOk()
        ->assertJsonPath('data.rejection_reason', 'Fotografia ilegível');

    expect($profile->fresh()?->verification_status->value)->toBe('rejected');
});

it('quem não é admin não acede à área de verificações', function () {
    apiLogin(TrainerProfile::factory()->create()->user);

    $this->getJson('/api/v1/admin/verifications')->assertForbidden();
});
