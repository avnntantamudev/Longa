<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Rotas transversais da API (prefixo /api/v1).
| As rotas de negócio vivem em app/Modules/{Modulo}/routes/api.php.
*/

Route::get('/health', HealthController::class)->name('api.health');
