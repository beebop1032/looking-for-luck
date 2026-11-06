<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * One winning tier of a lottery: how many main and bonus numbers it requires,
 * and an indicative average prize (pari-mutuel tiers vary at every draw).
 */
final class PrizeTier
{
    public function __construct(
        public readonly int $rank,
        public readonly int $mainMatches,
        public readonly int $bonusMatches,
        public readonly float $averagePrize,
    ) {
    }
}
