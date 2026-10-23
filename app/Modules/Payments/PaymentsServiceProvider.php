<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Services\MockPaymentProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentProvider::class, function (): PaymentProvider {
            return match (config('longa.payments.provider')) {
                'mock' => new MockPaymentProvider((string) config('longa.payments.mock_outcome')),
                // O gateway real entra aqui quando o cliente o escolher
                default => throw new InvalidArgumentException('Gateway de pagamento não suportado: '.config('longa.payments.provider')),
            };
        });
    }
}
