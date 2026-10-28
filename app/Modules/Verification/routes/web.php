<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'auth.session', 'role:admin|operator'])->group(function () {
    Route::get('/admin/verificacoes', fn () => Inertia::render('Admin/Verifications'))->name('admin.verifications');
});
