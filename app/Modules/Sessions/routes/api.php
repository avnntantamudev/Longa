<?php

declare(strict_types=1);

use App\Modules\Sessions\Http\Controllers\SessionController;
use App\Modules\Sessions\Http\Controllers\SessionExecutionController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::get('sessions', [SessionController::class, 'index'])->name('api.sessions.index');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->name('api.sessions.show');
    Route::get('sessions/{session}/messages', [SessionController::class, 'messages'])->name('api.sessions.messages');
    Route::post('sessions/{session}/messages', [SessionController::class, 'send'])->middleware('throttle:60,1')->name('api.sessions.messages.send');
    Route::get('messages/{message}/attachment', [SessionController::class, 'attachmentLink'])->name('api.messages.attachment');

    // Execução da aula (Fase 3)
    Route::post('sessions/{session}/start', [SessionExecutionController::class, 'start'])->name('api.sessions.start');
    Route::post('sessions/{session}/finish', [SessionExecutionController::class, 'finish'])->middleware('throttle:20,1')->name('api.sessions.finish');
    Route::post('sessions/{session}/qr', [SessionExecutionController::class, 'qr'])->middleware('throttle:30,1')->name('api.sessions.qr');
    Route::post('sessions/{session}/confirm', [SessionExecutionController::class, 'confirm'])
        ->middleware(['idempotent', 'throttle:qr-confirm'])
        ->name('api.sessions.confirm');
    Route::post('sessions/{session}/confirm-without-qr', [SessionExecutionController::class, 'confirmWithoutQr'])->middleware('idempotent')->name('api.sessions.confirm-without-qr');
    Route::post('sessions/{session}/cancel', [SessionExecutionController::class, 'cancel'])->middleware(['idempotent', 'throttle:10,1'])->name('api.sessions.cancel');
    Route::post('sessions/{session}/no-show', [SessionExecutionController::class, 'noShow'])->middleware(['idempotent', 'throttle:10,1'])->name('api.sessions.no-show');
});
