<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session'])->group(function () {
    Route::post('push-subscriptions', [NotificationController::class, 'subscribe'])->middleware('throttle:20,60')->name('api.push.subscribe');
    Route::delete('push-subscriptions', [NotificationController::class, 'unsubscribe'])->name('api.push.unsubscribe');

    Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('api.notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('api.notifications.read');
});
