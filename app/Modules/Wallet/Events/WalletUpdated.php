<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A carteira do técnico mudou (serviço confirmado, reembolso parcial, comissão em dinheiro...).
 * Só identificadores e o motivo: o cliente pede os saldos à API.
 */
class WalletUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $broadcastQueue = 'notifications';

    public function __construct(public readonly int $providerUserId, public readonly string $reason, public readonly ?int $sessionId = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->providerUserId);
    }

    public function broadcastAs(): string
    {
        return 'wallet.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'session_id' => $this->sessionId];
    }
}
