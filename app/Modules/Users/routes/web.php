<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\PageController;
use App\Modules\Users\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

// Fotos de perfil: nome aleatório de 32 caracteres (não adivinhável), imutável e com cache longa
Route::get('/fotos/{name}-{size}.webp', [PhotoController::class, 'show'])
    ->where(['name' => '[a-z0-9]{32}', 'size' => '[0-9]+'])
    ->name('photos.show');

// Páginas da PWA: os formulários chamam a API /api/v1 com o cookie de sessão (Sanctum SPA)
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/onboarding', [PageController::class, 'onboarding'])->name('onboarding.show');
    Route::get('/onboarding/formador', [PageController::class, 'trainerOnboarding'])->name('onboarding.trainer');
    Route::get('/onboarding/empresa', [PageController::class, 'companyOnboarding'])->name('onboarding.company');
    Route::get('/perfil', [PageController::class, 'profile'])->middleware('onboarded')->name('profile');
});
