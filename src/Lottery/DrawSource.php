<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Where a lottery's draw history comes from. Implementations keep an on-disk
 * cache, validate everything through Grid::of(), and fall back to the stale
 * cache when the source is unreachable.
 */
interface DrawSource
{
    /**
     * Every known draw, oldest first.
     *
     * @return list<Draw>
     *
     * @throws \RuntimeException when no history is available at all
     */
    public function all(?int $fromYear = null): array;

    /**
     * Re-downloads the most recent draws, ignoring the cache freshness.
     *
     * @return list<Draw> every known draw, oldest first
     *
     * @throws \RuntimeException when no history is available at all
     */
    public function refresh(): array;

    /** Year of the first draw in the history. */
    public function firstYear(): int;
}
