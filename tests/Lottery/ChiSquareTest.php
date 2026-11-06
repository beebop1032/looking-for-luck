<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Lottery\ChiSquare;
use PHPUnit\Framework\TestCase;

final class ChiSquareTest extends TestCase
{
    private ChiSquare $chiSquare;

    protected function setUp(): void
    {
        $this->chiSquare = new ChiSquare();
    }

    public function testUniformDistributionYieldsZeroStatistic(): void
    {
        $result = $this->chiSquare->uniformGoodnessOfFit(array_fill(1, 10, 100));

        self::assertSame(0.0, $result['statistic']);
        self::assertSame(9, $result['degreesOfFreedom']);
        self::assertEqualsWithDelta(1.0, $result['pValue'], 1e-9);
    }

    public function testKnownQuantiles(): void
    {
        // Classic chi-squared quantiles:
        // P(X >= 3.841) = 0.05 for df = 1; P(X >= 18.307) = 0.05 for df = 10.
        self::assertEqualsWithDelta(0.05, $this->chiSquare->upperTailProbability(3.841, 1), 1e-3);
        self::assertEqualsWithDelta(0.05, $this->chiSquare->upperTailProbability(18.307, 10), 1e-3);
        self::assertEqualsWithDelta(0.95, $this->chiSquare->upperTailProbability(3.940, 10), 1e-3);
    }

    public function testSkewedDistributionIsDetected(): void
    {
        $observed = array_fill(1, 10, 50);
        $observed[1] = 500; // one heavily over-represented category

        $result = $this->chiSquare->uniformGoodnessOfFit($observed);

        self::assertLessThan(0.001, $result['pValue']);
    }

    public function testRejectsDegenerateInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chiSquare->uniformGoodnessOfFit([1 => 10]);
    }
}
