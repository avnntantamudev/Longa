<?php

declare(strict_types=1);

use App\Modules\Sessions\Http\Controllers\DisputeController;
use App\Modules\Sessions\Http\Controllers\PageController;
use App\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Download de anexos do chat por link assinado temporário (gerado em /api/v1/messages/{id}/attachment)
Route::get('/chat/anexos/{message}', [SessionController::class, 'downloadAttachment'])
    ->middleware('signed:relative')
    ->name('chat.attachment');

// Evidências de disputas, também por link assinado temporário
Route::get('/disputas/anexos/{attachment}', [DisputeController::class, 'downloadAttachment'])
    ->middleware('signed:relative')
    ->name('disputes.attachment');

Route::middleware(['auth', 'auth.session', 'onboarded'])->group(function () {
    Route::get('/aulas', [PageController::class, 'index'])->name('sessions.index');
    Route::get('/aulas/{session}', [PageController::class, 'show'])->name('sessions.show');
    Route::get('/aulas/{session}/qr', [PageController::class, 'qr'])->name('sessions.qr');
    Route::get('/aulas/{session}/confirmar', [PageController::class, 'scan'])->name('sessions.scan');
    Route::get('/aulas/{session}/disputa', [PageController::class, 'dispute'])->name('sessions.dispute');
});
