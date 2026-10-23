<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

final class ProviderResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $failureReason = null,
        public readonly array $payload = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }
}
