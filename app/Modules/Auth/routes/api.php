<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\OtpController;
use App\Modules\Auth\Http\Controllers\PinController;
use App\Modules\Auth\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('otp/request', [OtpController::class, 'request'])->middleware('throttle:otp')->name('otp.request');
    Route::post('otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
    Route::post('pin/login', [PinController::class, 'login'])->middleware('throttle:otp-verify')->name('pin.login');
    Route::post('token/refresh', [SessionController::class, 'refresh'])->middleware('throttle:otp-verify')->name('token.refresh');

    Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

        Route::post('pin/setup', [PinController::class, 'setup'])->middleware('throttle:10,1')->name('pin.setup');
        Route::post('pin/change', [PinController::class, 'change'])->middleware('throttle:10,1')->name('pin.change');
        Route::post('pin/verify', [PinController::class, 'verify'])->middleware('throttle:20,1')->name('pin.verify');
        Route::get('devices', [PinController::class, 'devices'])->name('devices.index');
        Route::delete('devices/{device}', [PinController::class, 'revokeDevice'])->name('devices.destroy');
    });
});
