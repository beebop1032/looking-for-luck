<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\BudgetSimulator;
use App\Lottery\PrizeTable;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class BudgetSimulatorTest extends TestCase
{
    private BudgetSimulator $simulator;

    protected function setUp(): void
    {
        $game = new EuromillionsGame();
        $this->simulator = new BudgetSimulator($game, new PrizeTable($game));
    }

    public function testTenYearsOfOneGrid(): void
    {
        $result = $this->simulator->simulate(10, 1, 2.5, 4_000);

        self::assertSame(1_040, $result['gridsPlayed']);
        self::assertEqualsWithDelta(2_600.0, $result['totalCost'], 1e-9);
        // The expected loss is exact; a typical player loses a bit more (a few big wins pull the mean up).
        self::assertLessThan(0.0, $result['expectedNet']);
        self::assertLessThanOrEqual($result['expectedNet'], $result['median']);
        // What comes back is the net result plus the stake, ordered by percentile.
        self::assertEqualsWithDelta($result['median'] + $result['totalCost'], $result['wonBack']['median'], 1e-6);
        self::assertLessThanOrEqual($result['wonBack']['median'], $result['wonBack']['p5']);
        self::assertGreaterThanOrEqual($result['wonBack']['median'], $result['wonBack']['p95']);
        self::assertGreaterThan(0.0, $result['wonBack']['p5']);
    }

    public function testTheLongestSimulationAllowedStaysFast(): void
    {
        $start = hrtime(true);
        $result = $this->simulator->simulate(80, 100, 2.5, 2_000);

        self::assertSame(832_000, $result['gridsPlayed']);
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }

    #[TestWith([0, 1, 2.5])]
    #[TestWith([81, 1, 2.5])]
    #[TestWith([10, 0, 2.5])]
    #[TestWith([10, 101, 2.5])]
    #[TestWith([10, 1, 0.0])]
    public function testRejectsOutOfRangeInput(int $years, int $grids, float $price): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->simulator->simulate($years, $grids, $price, 1_000);
    }
}
