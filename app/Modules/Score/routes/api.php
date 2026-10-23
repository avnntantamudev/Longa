<?php

declare(strict_types=1);

use App\Modules\Score\Http\Controllers\ScoreEventController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me/score-events', [ScoreEventController::class, 'mine'])->middleware('role:trainer')->name('api.score-events.mine');
    Route::get('admin/trainers/{trainer}/score-events', [ScoreEventController::class, 'forTrainer'])
        ->middleware(['role:admin', 'audit:admin.access'])
        ->name('api.admin.score-events');
});
