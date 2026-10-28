<?php

declare(strict_types=1);

namespace App\Modules\Matching\Support;

final class MatchCandidate
{
    public function __construct(
        public readonly int $providerProfileId,
        public readonly int $userId,
        public readonly int $distanceM,
        public readonly int $score,
        public readonly float $rankScore,
    ) {}
}
