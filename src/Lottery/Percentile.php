<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Linear-interpolation percentile over a sorted series.
 */
final class Percentile
{
    private function __construct()
    {
    }

    /**
     * @param list<float> $sorted values sorted ascending
     */
    public static function of(array $sorted, float $percentile): float
    {
        $count = \count($sorted);
        if (0 === $count) {
            return 0.0;
        }

        $position = ($percentile / 100.0) * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $fraction = $position - $lower;

        return $sorted[$lower] + $fraction * ($sorted[$upper] - $sorted[$lower]);
    }
}
