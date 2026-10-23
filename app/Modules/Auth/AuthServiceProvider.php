<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Limites gerais por IP; os limites finos por telefone estão no OtpService
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by('otp:'.$request->ip()));
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(20)->by('otp-verify:'.$request->ip()));
    }
}
