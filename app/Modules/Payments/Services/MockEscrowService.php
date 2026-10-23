<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\EscrowService;
use App\Modules\Sessions\Models\TrainingSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Escrow simulado: regista as operações no log e devolve referências "MOCK-…".
 * Nenhum dinheiro é movido.
 */
class MockEscrowService implements EscrowService
{
    public function hold(TrainingSession $session): string
    {
        $reference = 'MOCK-'.Str::upper(Str::random(12));

        Log::info('escrow.hold', [
            'session_id' => $session->id,
            'reference' => $reference,
            'amount' => $session->amount,
            'commission' => $session->commission,
            'trainer_net' => $session->trainer_net,
        ]);

        return $reference;
    }

    public function release(TrainingSession $session): void
    {
        Log::info('escrow.release', ['session_id' => $session->id, 'reference' => $session->escrow_reference]);
    }

    public function refund(TrainingSession $session, string $reason): void
    {
        Log::info('escrow.refund', ['session_id' => $session->id, 'reference' => $session->escrow_reference, 'reason' => $reason]);
    }
}
