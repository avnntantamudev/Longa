<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\OtpController;
use App\Modules\Auth\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('otp/request', [OtpController::class, 'request'])->middleware('throttle:otp')->name('otp.request');
    Route::post('otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
    Route::post('token/refresh', [SessionController::class, 'refresh'])->middleware('throttle:otp-verify')->name('token.refresh');

    Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    });
});
