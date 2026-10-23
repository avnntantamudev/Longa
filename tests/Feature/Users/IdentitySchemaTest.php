<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Modules\Verification\Models\Verification;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\DB;

it('cifra o nº do BI na base de dados e nunca o serializa', function () {
    $user = User::factory()->create();
    $verification = new Verification(['user_id' => $user->id, 'type' => Verification::TYPE_IDENTITY]);
    $verification->setDocumentNumber('001234567la042');
    $verification->save();

    $raw = DB::table('verifications')->value('document_number');

    expect($raw)->not->toContain('001234567LA042')
        ->and($verification->fresh()?->document_number)->toBe('001234567LA042')
        ->and($verification->toArray())->not->toHaveKeys(['document_number', 'document_hash', 'documents'])
        ->and($verification->maskedDocumentNumber())->toBe('•••••••••LA042')
        ->and($verification->document_hash)->toBe(Verification::hashDocument('001234567LA042'));
});

it('gera a coluna espacial do formador a partir da latitude e longitude', function () {
    $profile = TrainerProfile::factory()->create();

    $point = DB::selectOne('select ST_Latitude(location) as lat, ST_Longitude(location) as lng from trainer_profiles where id = ?', [$profile->id]);

    expect(round((float) $point->lat, 4))->toBe(TrainerProfileFactory::TALATONA[0])
        ->and(round((float) $point->lng, 4))->toBe(TrainerProfileFactory::TALATONA[1])
        ->and($profile->toArray())->not->toHaveKey('location');
});

it('permite vários perfis no mesmo utilizador', function () {
    $user = User::factory()->withRole(Role::Student, Role::Trainer)->create();

    expect($user->hasProfile(Role::Student))->toBeTrue()
        ->and($user->hasProfile(Role::Trainer))->toBeTrue()
        ->and($user->hasProfile(Role::Admin))->toBeFalse();
});

it('garante telefone único', function () {
    User::factory()->create(['phone' => '+244923000000']);

    expect(fn () => User::factory()->create(['phone' => '+244923000000']))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
