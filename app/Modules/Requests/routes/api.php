<?php

declare(strict_types=1);

use App\Modules\Requests\Http\Controllers\TrainingRequestController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded', 'role:student'])
    ->prefix('training-requests')
    ->name('api.training-requests.')
    ->group(function () {
        Route::get('/', [TrainingRequestController::class, 'index'])->name('index');
        Route::post('/', [TrainingRequestController::class, 'store'])->middleware('throttle:training-requests')->name('store');
        Route::get('{trainingRequest}', [TrainingRequestController::class, 'show'])->name('show');
        Route::post('{trainingRequest}/cancel', [TrainingRequestController::class, 'cancel'])->name('cancel');
        Route::post('{trainingRequest}/republish', [TrainingRequestController::class, 'republish'])->middleware('throttle:training-requests')->name('republish');
    });
