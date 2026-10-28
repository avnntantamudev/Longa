<?php

declare(strict_types=1);

use App\Modules\Verification\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me/verifications', [VerificationController::class, 'mine'])->name('api.verifications.mine');
    Route::post('me/verifications', [VerificationController::class, 'store'])
        ->middleware(['role:provider', 'throttle:10,60'])
        ->name('api.verifications.store');

    Route::prefix('admin')->middleware(['role:admin', 'audit:admin.access'])->name('api.admin.')->group(function () {
        Route::get('verifications', [VerificationController::class, 'index'])->name('verifications.index');
        Route::post('verifications/{verification}/approve', [VerificationController::class, 'approve'])->name('verifications.approve');
        Route::post('verifications/{verification}/reject', [VerificationController::class, 'reject'])->name('verifications.reject');
        Route::post('users/{user}/photo/reject', [VerificationController::class, 'rejectPhoto'])->name('users.photo.reject');
    });
});
