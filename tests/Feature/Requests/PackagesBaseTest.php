<?php

declare(strict_types=1);

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\Category;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\UniqueConstraintViolationException;

it('no lançamento só Educação aceita pacotes, e as subcategorias herdam (com exceção explícita)', function () {
    $education = Category::query()->where('slug', 'educacao')->firstOrFail();
    $lessons = Category::query()->where('slug', 'aulas-e-explicacoes')->firstOrFail();
    $home = Category::query()->where('slug', 'casa')->firstOrFail();

    expect($education->acceptsPackages())->toBeTrue()
        ->and($lessons->packages_enabled)->toBeNull()
        ->and($lessons->acceptsPackages())->toBeTrue()
        ->and($home->acceptsPackages())->toBeFalse();

    $lessons->update(['packages_enabled' => false]);
    expect($lessons->fresh()?->acceptsPackages())->toBeFalse();

    $home->update(['packages_enabled' => true]);
    $homeChild = Category::query()->where('parent_id', $home->id)->firstOrFail();
    expect($homeChild->acceptsPackages())->toBeTrue();
});

it('um pacote é um pedido-contrato com aulas filhas pela ordem, sem números repetidos', function () {
    $package = ServiceRequest::factory()->create(['sessions' => 3]);
    $client = ['client_id' => $package->client_id, 'category_id' => $package->category_id];
    foreach ([2, 1, 3] as $n) {
        ServiceRequest::factory()->create([...$client, 'package_id' => $package->id, 'package_sequence' => $n, 'scheduled_at' => now()->addDays($n)]);
    }

    expect($package->lessons->pluck('package_sequence')->all())->toBe([1, 2, 3])
        ->and($package->isPackageLesson())->toBeFalse()
        ->and($package->lessons->first()?->isPackageLesson())->toBeTrue()
        ->and($package->lessons->first()?->package?->is($package))->toBeTrue();

    expect(fn () => ServiceRequest::factory()->create([...$client, 'package_id' => $package->id, 'package_sequence' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('as regras dos pacotes são editáveis no painel com os valores combinados', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->int('packages_vip_window_minutes'))->toBe(20)
        ->and($settings->int('packages_access'))->toBe(1)
        ->and($settings->int('direct_requests_vip_only'))->toBe(1)
        ->and($settings->int('packages_min_rating_tenths'))->toBe(40)
        ->and($settings->int('packages_min_rating_count'))->toBe(5)
        ->and($settings->int('packages_max_sessions'))->toBe(20)
        ->and($settings->int('packages_max_weeks'))->toBe(4)
        ->and($settings->int('direct_request_minutes'))->toBe(30)
        ->and($settings->int('vip_commission_percent'))->toBe(10);
});
