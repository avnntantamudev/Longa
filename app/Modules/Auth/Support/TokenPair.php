<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * Par de tokens da API: acesso (curto) + refresh (longo, rodado em cada uso).
 */
final class TokenPair
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $accessExpiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshExpiresIn,
        public readonly string $sessionId,
    ) {}

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int, session_id: string}
     */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'expires_in' => $this->accessExpiresIn,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_in' => $this->refreshExpiresIn,
            'session_id' => $this->sessionId,
        ];
    }
}
