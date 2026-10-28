<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\AvailabilityController;
use App\Modules\Users\Http\Controllers\CatalogController;
use App\Modules\Users\Http\Controllers\CategorySuggestionController;
use App\Modules\Users\Http\Controllers\MeController;
use App\Modules\Users\Http\Controllers\PhotoController;
use App\Modules\Users\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::get('categories', [CatalogController::class, 'categories'])->name('api.categories');
Route::get('zones', [CatalogController::class, 'zones'])->name('api.zones');
Route::get('provinces', [CatalogController::class, 'provinces'])->name('api.provinces');
Route::get('providers/{provider}', [CatalogController::class, 'provider'])->name('api.providers.show');

Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::get('me', MeController::class)->name('api.me');
    Route::patch('me', [ProfileController::class, 'updateMe'])->name('api.me.update');
    Route::post('me/photo', [PhotoController::class, 'store'])->middleware('throttle:10,1')->name('api.me.photo');
    Route::delete('me/photo', [PhotoController::class, 'destroy'])->name('api.me.photo.destroy');
    Route::post('onboarding', [ProfileController::class, 'onboarding'])->name('api.onboarding');

    Route::put('me/client-profile', [ProfileController::class, 'updateClient'])->name('api.me.client');

    Route::get('me/provider-profile', [ProfileController::class, 'showProvider'])->name('api.me.provider');
    Route::put('me/provider-profile/details', [ProfileController::class, 'providerDetails'])->name('api.me.provider.details');
    Route::put('me/provider-profile/categories', [ProfileController::class, 'providerCategories'])->name('api.me.provider.categories');
    Route::get('me/provider-profile/category-suggestions', [CategorySuggestionController::class, 'index'])->name('api.me.provider.category-suggestions');
    Route::post('me/provider-profile/category-suggestions', [CategorySuggestionController::class, 'store'])->middleware('throttle:10,1')->name('api.me.provider.category-suggestions.store');
    Route::put('me/provider-profile/location', [ProfileController::class, 'providerLocation'])->name('api.me.provider.location');
    Route::put('me/provider-profile/availability', AvailabilityController::class)->middleware('role:provider')->name('api.me.provider.availability');
});
