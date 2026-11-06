<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Lottery\Percentile;
use PHPUnit\Framework\TestCase;

final class PercentileTest extends TestCase
{
    public function testMedianOfOddSeries(): void
    {
        self::assertSame(3.0, Percentile::of([1.0, 2.0, 3.0, 4.0, 5.0], 50.0));
    }

    public function testMedianOfEvenSeriesInterpolates(): void
    {
        self::assertSame(2.5, Percentile::of([1.0, 2.0, 3.0, 4.0], 50.0));
    }

    public function testExtremes(): void
    {
        $series = [10.0, 20.0, 30.0];

        self::assertSame(10.0, Percentile::of($series, 0.0));
        self::assertSame(30.0, Percentile::of($series, 100.0));
    }

    public function testEmptySeries(): void
    {
        self::assertSame(0.0, Percentile::of([], 50.0));
    }
}
