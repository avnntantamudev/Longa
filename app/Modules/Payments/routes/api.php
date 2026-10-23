<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider). Endpoints financeiros exigem Idempotency-Key.
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/pay', [PaymentController::class, 'pay'])
        ->middleware(['idempotent', 'throttle:10,1'])
        ->name('api.sessions.pay');
});
