<?php

declare(strict_types=1);

use App\Modules\Payments\Services\MockEscrowService;
use App\Modules\Sessions\Models\TrainingSession;

it('devolve uma referência mock sem mover dinheiro', function () {
    $session = new TrainingSession(['amount' => 25000, 'commission' => 3750, 'trainer_net' => 21250]);
    $session->id = 1;

    $reference = (new MockEscrowService)->hold($session);

    expect($reference)->toStartWith('MOCK-')->toHaveLength(17);
})->skip(fn () => ! function_exists('app') || ! app()->bound('log'), 'precisa do contentor');
