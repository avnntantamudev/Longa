<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\WebLoginController;
use App\Modules\Auth\Http\Controllers\WebPinController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [WebLoginController::class, 'show'])->name('login');
    Route::post('/entrar/codigo', [WebLoginController::class, 'sendCode'])->middleware('throttle:otp')->name('login.code');
    Route::post('/entrar/verificar', [WebLoginController::class, 'verify'])->middleware('throttle:otp-verify')->name('login.verify');
    Route::post('/entrar/alterar-numero', [WebLoginController::class, 'changePhone'])->name('login.change-phone');
    Route::post('/entrar/pin', [WebPinController::class, 'login'])->middleware('throttle:otp-verify')->name('login.pin');
    Route::post('/entrar/outra-conta', [WebPinController::class, 'otherAccount'])->name('login.other-account');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/pin/criar', [WebPinController::class, 'setupPage'])->name('pin.setup');
    Route::post('/pin/criar', [WebPinController::class, 'setup'])->middleware('throttle:10,1')->name('pin.setup.store');
    Route::get('/desbloquear', [WebPinController::class, 'unlockPage'])->name('pin.unlock');
    Route::post('/desbloquear', [WebPinController::class, 'unlock'])->middleware('throttle:20,1')->name('pin.unlock.store');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/sair', [WebLoginController::class, 'logout'])->name('logout');
});
