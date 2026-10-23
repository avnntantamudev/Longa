<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Contracts\EscrowService;
use App\Modules\Payments\Services\MockEscrowService;
use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fase 3: trocar pela implementação real quando o meio de pagamento estiver decidido
        $this->app->bind(EscrowService::class, MockEscrowService::class);
    }
}
