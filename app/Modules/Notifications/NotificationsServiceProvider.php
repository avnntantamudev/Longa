<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function (): SmsProvider {
            $driver = config('longa.sms.driver');

            return match ($driver) {
                'log' => new LogSmsProvider,
                'array' => new ArraySmsProvider,
                // O provedor real será integrado depois de o cliente o escolher
                default => throw new InvalidArgumentException("Driver de SMS não suportado: {$driver}"),
            };
        });
    }
}
