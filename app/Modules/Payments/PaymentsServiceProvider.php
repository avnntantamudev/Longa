<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Gateways\CardGateway;
use App\Modules\Payments\Gateways\MulticaixaExpressGateway;
use App\Modules\Payments\Gateways\PaymentGatewayRouter;
use App\Modules\Payments\Gateways\ReferenceGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentProvider::class, function (): PaymentProvider {
            $outcome = (string) config('longa.payments.mock_outcome');

            return match (config('longa.payments.provider')) {
                // Todos os meios digitais simulados, com a mesma interface que o fornecedor real
                'mock' => new PaymentGatewayRouter([
                    PaymentMethod::MulticaixaExpress->value => new MulticaixaExpressGateway($outcome),
                    PaymentMethod::Reference->value => new ReferenceGateway($outcome),
                    PaymentMethod::Card->value => new CardGateway($outcome),
                ]),
                // O gateway real entra aqui quando o cliente o escolher
                default => throw new InvalidArgumentException('Gateway de pagamento não suportado: '.config('longa.payments.provider')),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\VipRemindersCommand::class]);
        }
    }
}
