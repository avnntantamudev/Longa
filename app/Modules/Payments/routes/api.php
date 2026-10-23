<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\CashController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider). Endpoints financeiros exigem Idempotency-Key.
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/pay', [PaymentController::class, 'pay'])
        ->middleware(['idempotent', 'throttle:10,1'])
        ->name('api.sessions.pay');
});

Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/confirm-cash', [CashController::class, 'confirm'])->middleware('idempotent')->name('api.sessions.confirm-cash');

    Route::post('admin/trainers/{trainer}/commission-payments', [CashController::class, 'receive'])
        ->middleware(['role:admin', 'idempotent', 'audit:admin.commission_received'])
        ->name('api.admin.commission-payments');
});
