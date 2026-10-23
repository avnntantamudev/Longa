<?php

declare(strict_types=1);

it('tem um manifest válido com ícones 192, 512 e maskable', function () {
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../public/manifest.webmanifest'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('LONGA')
        ->display->toBe('standalone')
        ->theme_color->toBe('#0B1F3A')
        ->scope->toBe('/');

    $icons = collect($manifest['icons']);

    expect($icons->pluck('sizes')->unique()->values()->all())->toBe(['192x192', '512x512'])
        ->and($icons->where('purpose', 'maskable'))->toHaveCount(2);

    $icons->each(fn (array $icon) => expect(file_exists(__DIR__.'/../../public'.$icon['src']))->toBeTrue());
});

it('tem a página offline', function () {
    expect(file_get_contents(__DIR__.'/../../public/offline.html'))->toContain('Sem ligação à internet');
});
