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
    ) {
    }
}
