<?php

declare(strict_types=1);

namespace App\Modules\Matching\Jobs;

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Models\TrainingRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Agendado para o fim de cada onda: expira as ofertas da onda e envia a seguinte (ou expira o pedido).
 * A varredura `longa:matching-tick` (a cada 10 s) cobre o caso de este job se atrasar ou falhar.
 */
class AdvanceMatching implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('default');
    }

    public function handle(MatchingCoordinator $coordinator): void
    {
        $request = TrainingRequest::query()->find($this->requestId);

        if ($request !== null) {
            $coordinator->advance($request);
        }
    }
}
