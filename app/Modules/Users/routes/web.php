<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Páginas da PWA: os formulários chamam a API /api/v1 com o cookie de sessão (Sanctum SPA)
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/onboarding', [PageController::class, 'onboarding'])->name('onboarding.show');
    Route::get('/onboarding/formador', [PageController::class, 'trainerOnboarding'])->name('onboarding.trainer');
    Route::get('/onboarding/empresa', [PageController::class, 'companyOnboarding'])->name('onboarding.company');
    Route::get('/perfil', [PageController::class, 'profile'])->middleware('onboarded')->name('profile');
});
