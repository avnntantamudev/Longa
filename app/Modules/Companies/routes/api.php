<?php

declare(strict_types=1);

use App\Modules\Companies\Http\Controllers\CompanyController;
use App\Modules\Companies\Http\Controllers\CompanyReportController;
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

    // Relatório RH (só colaboradores que autorizaram)
    Route::get('{company}/reports/training', [CompanyReportController::class, 'show'])->name('reports.training');
    Route::get('{company}/reports/training/export', [CompanyReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.training.export');
});

// O colaborador decide se a empresa vê as suas formações
Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me/company-links', [CompanyReportController::class, 'myLinks'])->name('api.me.company-links');
    Route::post('me/company-links/{employee}', [CompanyReportController::class, 'decide'])->middleware('throttle:20,1')->name('api.me.company-links.decide');
});
