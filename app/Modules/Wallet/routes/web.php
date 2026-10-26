<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'auth.session', 'onboarded', 'role:trainer'])->group(function () {
    Route::get('/carteira', fn () => Inertia::render('Wallet/Show'))->name('wallet.show');
    Route::get('/vip', fn () => Inertia::render('Vip/Show'))->name('vip.show');
    Route::get('/carteira/ganhos', fn () => Inertia::render('Wallet/Earnings'))->name('wallet.earnings');
});
