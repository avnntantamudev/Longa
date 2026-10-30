<?php

declare(strict_types=1);

use App\Modules\Ratings\Http\Controllers\RatingController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::get('sessions/{session}/ratings', [RatingController::class, 'index'])->name('api.sessions.ratings.index');
    Route::post('sessions/{session}/ratings', [RatingController::class, 'store'])->middleware('throttle:10,1')->name('api.sessions.ratings.store');
});
