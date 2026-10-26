<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'auth.session', 'onboarded', 'role:company_rh'])->group(function () {
    Route::get('/empresa/relatorios', fn () => Inertia::render('Company/Reports'))->name('company.reports');
});
