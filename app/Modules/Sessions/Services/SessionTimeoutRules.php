<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\TrainingSession;
use App\Support\StateMachine\InvalidTransition;
use Illuminate\Support\Facades\DB;

/**
 * Regras automáticas da aula, todas aqui e configuradas em config/longa.php → session_rules
 * (docs/state-machines.md). Corridas por `php artisan longa:sessions:tick` (scheduler, a cada minuto).
 * Cada regra é idempotente e usa transições condicionais: correr duas vezes não tem efeito extra.
 */
class SessionTimeoutRules
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    /**
     * @return array<string, int> número de aulas afetadas por regra
     */
    public function run(): array
    {
        return [
            'R3_payment_expired' => $this->expireUnpaid(),
        ];
    }

    /**
     * R3: aula à espera de pagamento com o prazo vencido → payment_failed (formador libertado).
     */
    public function expireUnpaid(): int
    {
        $count = 0;

        TrainingSession::query()
            ->where('state', SessionState::PendingPayment->value)
            ->where('payment_due_at', '<=', now())
            ->limit(200)
            ->get()
            ->each(function (TrainingSession $session) use (&$count) {
                DB::transaction(function () use ($session, &$count) {
                    try {
                        $this->lifecycle->transition($session, SessionState::PaymentFailed, 'payment_window_expired', null, ['rule' => 'R3']);
                        $count++;
                    } catch (InvalidTransition) {
                        // Pago entretanto
                    }
                });
            });

        return $count;
    }
}
