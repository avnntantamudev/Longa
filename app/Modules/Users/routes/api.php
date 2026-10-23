<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\AvailabilityController;
use App\Modules\Users\Http\Controllers\CatalogController;
use App\Modules\Users\Http\Controllers\MeController;
use App\Modules\Users\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::get('skills', [CatalogController::class, 'skills'])->name('api.skills');
Route::get('zones', [CatalogController::class, 'zones'])->name('api.zones');
Route::get('trainers/{trainer}', [CatalogController::class, 'trainer'])->name('api.trainers.show');

Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me', MeController::class)->name('api.me');
    Route::patch('me', [ProfileController::class, 'updateMe'])->name('api.me.update');
    Route::post('onboarding', [ProfileController::class, 'onboarding'])->name('api.onboarding');

    Route::put('me/student-profile', [ProfileController::class, 'updateStudent'])->name('api.me.student');

    Route::get('me/trainer-profile', [ProfileController::class, 'showTrainer'])->name('api.me.trainer');
    Route::put('me/trainer-profile/details', [ProfileController::class, 'trainerDetails'])->name('api.me.trainer.details');
    Route::put('me/trainer-profile/skills', [ProfileController::class, 'trainerSkills'])->name('api.me.trainer.skills');
    Route::put('me/trainer-profile/location', [ProfileController::class, 'trainerLocation'])->name('api.me.trainer.location');
    Route::put('me/trainer-profile/availability', AvailabilityController::class)->middleware('role:trainer')->name('api.me.trainer.availability');
});
