<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * The storage cannot hold more grids (a browser cookie is limited to 4 KB).
 */
final class GridStorageFullException extends \OverflowException
{
}
