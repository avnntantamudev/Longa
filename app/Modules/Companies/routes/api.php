<?php

declare(strict_types=1);

use App\Modules\Companies\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session'])->prefix('companies')->name('api.companies.')->group(function () {
    Route::get('/', [CompanyController::class, 'index'])->name('index');
    Route::post('/', [CompanyController::class, 'store'])->middleware('throttle:10,60')->name('store');
    Route::get('{company}', [CompanyController::class, 'show'])->name('show');
    Route::patch('{company}', [CompanyController::class, 'update'])->name('update');

    Route::get('{company}/employees', [CompanyController::class, 'employees'])->name('employees.index');
    Route::post('{company}/employees', [CompanyController::class, 'addEmployee'])->name('employees.store');
    Route::patch('{company}/employees/{employee}', [CompanyController::class, 'updateEmployee'])->name('employees.update');
    Route::delete('{company}/employees/{employee}', [CompanyController::class, 'removeEmployee'])->name('employees.destroy');
});
