<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * A grid the user saved locally, with its bookkeeping dates: when it was
 * saved, and the date of the last draw it was checked against.
 */
final class SavedGrid
{
    public function __construct(
        public readonly int $id,
        public readonly Grid $grid,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastDrawChecked,
    ) {
    }
}
