<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\Grid;

/**
 * A grid the user saved locally, with its bookkeeping dates.
 */
final class SavedGrid
{
    public function __construct(
        public readonly int $id,
        public readonly Grid $grid,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastCheckedAt,
    ) {
    }
}
