<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'auth.session', 'onboarded', 'role:company_rh'])->group(function () {
    Route::redirect('/empresa', '/empresa/equipa')->name('company.home');
    Route::get('/empresa/equipa', fn () => Inertia::render('Company/Team'))->name('company.team');
    Route::get('/empresa/relatorios', fn () => Inertia::render('Company/Reports'))->name('company.reports');
});
