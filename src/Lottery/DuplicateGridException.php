<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Thrown when saving a grid that is already in "my grids".
 */
final class DuplicateGridException extends \InvalidArgumentException
{
    public function __construct(public readonly int $existingId)
    {
        parent::__construct(\sprintf('This grid is already saved as #%d.', $existingId));
    }
}
