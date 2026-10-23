<?php

declare(strict_types=1);

use App\Modules\Auth\Support\PhoneNumber;

it('normaliza telemóveis angolanos para E.164', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('+244923456789');
})->with([
    'local' => '923456789',
    'com espaços' => '923 456 789',
    'com hífens' => '923-456-789',
    'internacional' => '+244 923 456 789',
    'prefixo 00' => '00244923456789',
    'sem +' => '244923456789',
]);

it('rejeita números que não são telemóveis angolanos', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull();
})->with([
    'fixo' => '222123456',
    'curto' => '92345678',
    'longo' => '9234567890',
    'outro país' => '+351912345678',
    'texto' => 'abc',
    'vazio' => '',
]);

it('formata e esconde o número', function () {
    $phone = PhoneNumber::parse('923456789');

    expect($phone?->formatted())->toBe('+244 923 456 789')
        ->and($phone?->masked())->toBe('+244 9•• ••• 789');
});
