<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me', MeController::class)->name('api.me');
});
