<?php

declare(strict_types=1);

use App\Modules\Requests\Http\Controllers\AddendumController;
use App\Modules\Requests\Http\Controllers\OfferController;
use App\Modules\Requests\Http\Controllers\QuoteController;
use App\Modules\Requests\Http\Controllers\RequestMediaController;
use App\Modules\Requests\Http\Controllers\ServiceRequestController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 (ModuleServiceProvider)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded', 'role:client'])
    ->prefix('service-requests')
    ->name('api.service-requests.')
    ->group(function () {
        Route::get('/', [ServiceRequestController::class, 'index'])->name('index');
        Route::post('/', [ServiceRequestController::class, 'store'])->middleware('throttle:service-requests')->name('store');
        Route::get('{serviceRequest}', [ServiceRequestController::class, 'show'])->name('show');
        Route::post('{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel'])->name('cancel');
        Route::post('{serviceRequest}/retry', [ServiceRequestController::class, 'retry'])->middleware('throttle:service-requests')->name('retry');
        Route::post('{serviceRequest}/republish', [ServiceRequestController::class, 'republish'])->middleware('throttle:service-requests')->name('republish');
    });

Route::middleware(['auth:sanctum', 'auth.session', 'onboarded', 'role:provider'])
    ->prefix('offers')
    ->name('api.offers.')
    ->group(function () {
        Route::get('/', [OfferController::class, 'index'])->name('index');
        Route::get('{offer}', [OfferController::class, 'show'])->name('show');
        Route::post('{offer}/view', [OfferController::class, 'view'])->name('view');
        Route::post('{offer}/accept', [OfferController::class, 'accept'])->middleware('throttle:offer-responses')->name('accept');
        Route::post('{offer}/decline', [OfferController::class, 'decline'])->middleware('throttle:offer-responses')->name('decline');
    });

// Orçamentos (serviços por orçamento): diagnóstico e orçamento do técnico, resposta do cliente
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])->group(function () {
    Route::post('sessions/{session}/diagnosis', [QuoteController::class, 'diagnosis'])->middleware('idempotent')->name('api.sessions.diagnosis');
    Route::get('sessions/{session}/quotes', [QuoteController::class, 'index'])->name('api.sessions.quotes.index');
    Route::post('sessions/{session}/quotes', [QuoteController::class, 'store'])->middleware(['idempotent', 'throttle:10,1'])->name('api.sessions.quotes.store');
    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->middleware('idempotent')->name('api.quotes.accept');
    Route::post('quotes/{quote}/revision', [QuoteController::class, 'revision'])->middleware('idempotent')->name('api.quotes.revision');
    Route::post('quotes/{quote}/refuse', [QuoteController::class, 'refuse'])->middleware('idempotent')->name('api.quotes.refuse');

    // Adendas ao orçamento aceite
    Route::get('sessions/{session}/addenda', [AddendumController::class, 'index'])->name('api.sessions.addenda.index');
    Route::post('sessions/{session}/addenda', [AddendumController::class, 'store'])->middleware(['idempotent', 'throttle:10,1'])->name('api.sessions.addenda.store');
    Route::post('addenda/{addendum}/approve', [AddendumController::class, 'approve'])->middleware('idempotent')->name('api.addenda.approve');
    Route::post('addenda/{addendum}/reject', [AddendumController::class, 'reject'])->middleware('idempotent')->name('api.addenda.reject');
    Route::post('addenda/{addendum}/pay', [AddendumController::class, 'pay'])->middleware(['idempotent', 'throttle:10,1'])->name('api.addenda.pay');
});

// Fotos e vídeos do formulário do pedido (cliente, técnicos com oferta, técnico atribuído e equipa)
Route::middleware(['auth:sanctum', 'auth.session', 'onboarded'])
    ->get('request-media/{media}', [RequestMediaController::class, 'link'])
    ->name('api.request-media.link');
