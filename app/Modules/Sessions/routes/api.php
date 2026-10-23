<?php

declare(strict_types=1);

use App\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::get('sessions', [SessionController::class, 'index'])->name('api.sessions.index');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->name('api.sessions.show');
    Route::get('sessions/{session}/messages', [SessionController::class, 'messages'])->name('api.sessions.messages');
    Route::post('sessions/{session}/messages', [SessionController::class, 'send'])->middleware('throttle:60,1')->name('api.sessions.messages.send');
    Route::get('messages/{message}/attachment', [SessionController::class, 'attachmentLink'])->name('api.messages.attachment');
});
