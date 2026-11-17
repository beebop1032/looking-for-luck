<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * A historical draw: a date and the drawn grid.
 */
final class Draw
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly Grid $grid,
        /** Jackpot on offer at this draw, when the source provides it. */
        public readonly ?float $jackpot = null,
        /** Number of tier-1 winners, when the source provides it. */
        public readonly ?int $jackpotWinners = null,
    ) {
    }
}
