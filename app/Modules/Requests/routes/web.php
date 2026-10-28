<?php

declare(strict_types=1);

use App\Modules\Requests\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'onboarded'])->group(function () {
    Route::middleware('role:client')->group(function () {
        Route::get('/pedidos/novo', [PageController::class, 'createRequest'])->name('requests.create');
        Route::get('/pedidos/{serviceRequest}', [PageController::class, 'showRequest'])->name('requests.show');
    });

    Route::middleware('role:provider')->group(function () {
        Route::get('/formador/ofertas', [PageController::class, 'offers'])->name('provider.offers');
        Route::get('/formador/ofertas/{offer}', [PageController::class, 'showOffer'])->name('provider.offers.show');
    });
});
