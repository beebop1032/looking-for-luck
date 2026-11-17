<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Chooses where a lottery's grids are kept (see GridStorage).
 */
interface GridStorageFactory
{
    public function create(string $slug, string $dataDir): GridStorage;
}
