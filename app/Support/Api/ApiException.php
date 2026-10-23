<?php

declare(strict_types=1);

namespace App\Support\Api;

use RuntimeException;

/**
 * Exceção de domínio com código estável e mensagem em português, devolvida tal como está ao cliente.
 * Ex.: throw new ApiException('otp_locked', 'Demasiadas tentativas. Tente mais tarde.', 429);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
