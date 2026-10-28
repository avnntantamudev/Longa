<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

use App\Modules\Sessions\Models\ServiceJob;

/**
 * Política de reembolso (config/longa.php → session_rules). Devolve a parte do valor que fica para o
 * formador ANTES da comissão; o resto é reembolsado ao aluno. Um só sítio para todas as regras.
 */
class RefundPolicy
{
    /**
     * Aluno cancela: ≥ N horas antes → 0 para o formador (reembolso total); depois → X% para o formador.
     */
    public function clientCancellation(ServiceJob $session): int
    {
        return $this->isLate($session) ? $this->percentOf($session->amount, (int) config('longa.session_rules.late_cancel_provider_share_percent')) : 0;
    }

    /**
     * Formador cancela ou falta: reembolso total.
     */
    public function providerFault(ServiceJob $session): int
    {
        return 0;
    }

    /**
     * Falta do aluno: X% para o formador.
     */
    public function clientNoShow(ServiceJob $session): int
    {
        return $this->percentOf($session->amount, (int) config('longa.session_rules.no_show_client_provider_share_percent'));
    }

    public function isLate(ServiceJob $session): bool
    {
        return now()->gt($session->scheduled_at->copy()->subHours((int) config('longa.session_rules.late_cancel_hours')));
    }

    /**
     * Percentagem em Kz inteiros (meio para cima), nunca acima do total.
     */
    private function percentOf(int $amount, int $percent): int
    {
        return min($amount, intdiv($amount * $percent + 50, 100));
    }
}
