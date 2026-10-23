<?php

declare(strict_types=1);

use App\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Download de anexos do chat por link assinado temporário (gerado em /api/v1/messages/{id}/attachment)
Route::get('/chat/anexos/{message}', [SessionController::class, 'downloadAttachment'])
    ->middleware('signed:relative')
    ->name('chat.attachment');
