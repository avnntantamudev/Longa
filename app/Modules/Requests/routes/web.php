<?php

declare(strict_types=1);

use App\Modules\Requests\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'onboarded'])->group(function () {
    Route::middleware('role:student')->group(function () {
        Route::get('/pedidos/novo', [PageController::class, 'createRequest'])->name('requests.create');
        Route::get('/pedidos/{trainingRequest}', [PageController::class, 'showRequest'])->name('requests.show');
    });

    Route::middleware('role:trainer')->group(function () {
        Route::get('/formador/ofertas', [PageController::class, 'offers'])->name('trainer.offers');
        Route::get('/formador/ofertas/{offer}', [PageController::class, 'showOffer'])->name('trainer.offers.show');
    });
});
