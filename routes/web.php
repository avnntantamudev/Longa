<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Referência visual dos componentes, apenas em desenvolvimento
if (app()->isLocal()) {
    Route::inertia('/_kit', 'Dev/Kit')->name('dev.kit');
}
