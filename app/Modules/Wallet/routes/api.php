<?php

declare(strict_types=1);

use App\Modules\Wallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded', 'role:trainer'])->group(function () {
    Route::get('wallet', [WalletController::class, 'show'])->name('api.wallet.show');
    Route::get('wallet/movements', [WalletController::class, 'movements'])->name('api.wallet.movements');
});
