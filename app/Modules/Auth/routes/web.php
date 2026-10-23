<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [WebLoginController::class, 'show'])->name('login');
    Route::post('/entrar/codigo', [WebLoginController::class, 'sendCode'])->middleware('throttle:otp')->name('login.code');
    Route::post('/entrar/verificar', [WebLoginController::class, 'verify'])->middleware('throttle:otp-verify')->name('login.verify');
    Route::post('/entrar/alterar-numero', [WebLoginController::class, 'changePhone'])->name('login.change-phone');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/sair', [WebLoginController::class, 'logout'])->name('logout');
});
