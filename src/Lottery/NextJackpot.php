<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * The jackpot announced for an upcoming draw (an estimate published by the
 * operator, not a guarantee).
 */
final class NextJackpot
{
    public function __construct(
        public readonly \DateTimeImmutable $drawDate,
        public readonly float $amount,
    ) {
    }
}
