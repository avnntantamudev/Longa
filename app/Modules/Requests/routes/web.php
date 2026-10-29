<?php

declare(strict_types=1);

use App\Modules\Requests\Http\Controllers\PageController;
use App\Modules\Requests\Http\Controllers\RequestMediaController;
use Illuminate\Support\Facades\Route;

// Fotos e vídeos do pedido por link assinado temporário (gerado em /api/v1/request-media/{id})
Route::get('/pedidos/ficheiros/{media}', [RequestMediaController::class, 'download'])
    ->middleware('signed:relative')
    ->name('requests.media');

Route::middleware(['auth', 'auth.session', 'onboarded'])->group(function () {
    Route::middleware('role:client')->group(function () {
        Route::get('/pedidos/novo', [PageController::class, 'createRequest'])->name('requests.create');
        Route::get('/pedidos/{serviceRequest}', [PageController::class, 'showRequest'])->name('requests.show');
    });

    Route::middleware('role:provider')->group(function () {
        Route::get('/tecnico/ofertas', [PageController::class, 'offers'])->name('provider.offers');
        Route::get('/tecnico/ofertas/{offer}', [PageController::class, 'showOffer'])->name('provider.offers.show');
    });
});
