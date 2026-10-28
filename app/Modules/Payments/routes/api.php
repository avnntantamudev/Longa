<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\CashController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\VipController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider). Endpoints financeiros exigem Idempotency-Key.
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/pay', [PaymentController::class, 'pay'])
        ->middleware(['pin.recent', 'idempotent', 'throttle:10,1'])
        ->name('api.sessions.pay');
});

Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/confirm-cash', [CashController::class, 'confirm'])->middleware('idempotent')->name('api.sessions.confirm-cash');

    // Formador VIP
    Route::get('me/vip', [VipController::class, 'show'])->middleware('role:provider')->name('api.vip.show');
    Route::post('me/vip/purchase', [VipController::class, 'purchase'])
        ->middleware(['role:provider', 'pin.recent', 'idempotent', 'throttle:5,1'])
        ->name('api.vip.purchase');

    Route::post('admin/providers/{provider}/commission-payments', [CashController::class, 'receive'])
        ->middleware(['role:admin', 'idempotent', 'audit:admin.commission_received'])
        ->name('api.admin.commission-payments');
});
