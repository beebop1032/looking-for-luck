<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\BudgetSimulator;
use App\Lottery\PrizeTable;
use PHPUnit\Framework\TestCase;

final class BudgetChancesTest extends TestCase
{
    public function testOneGridMatchesThePrizeTable(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $chances = (new BudgetSimulator($game, $table))->chances(1, $table);

        foreach ($chances['tiers'] as $i => $tier) {
            self::assertEqualsWithDelta($table->ranks()[$i]['probability'], $tier['atLeastOnce'], 1e-15);
            self::assertEqualsWithDelta($tier['probabilityPerGrid'], $tier['expectedWins'], 1e-15);
        }
        self::assertEqualsWithDelta($table->anyWinProbability(), $chances['any']['atLeastOnce'], 1e-15);
    }

    public function testChancesAddUpOverTenYears(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $grids = 10 * 52 * 2; // one grid at every draw for ten years
        $chances = (new BudgetSimulator($game, $table))->chances($grids, $table);

        // Jackpot: 1 − (1 − 1/139,838,160)^1040 ≈ 1 in 134,460.
        self::assertEqualsWithDelta(1 / 134_460, $chances['tiers'][0]['atLeastOnce'], 1e-9);
        // Winning something is near certain, about 80 times.
        self::assertGreaterThan(0.999, $chances['any']['atLeastOnce']);
        self::assertEqualsWithDelta(80.2, $chances['any']['expectedWins'], 0.1);
        // Never above certainty, and more grids never mean lower chances.
        foreach ($chances['tiers'] as $tier) {
            self::assertLessThanOrEqual(1.0, $tier['atLeastOnce']);
        }
    }

    public function testNoGridMeansNoChance(): void
    {
        $game = new EuromillionsGame();
        $chances = (new BudgetSimulator($game, new PrizeTable($game)))->chances(0);

        self::assertSame(0.0, $chances['any']['atLeastOnce']);
    }

    public function testJackpotIsCappedByTheGame(): void
    {
        $game = new EuromillionsGame();

        self::assertSame(250_000_000.0, (new PrizeTable($game, 5_000_000_000))->prizeFor(1));
        self::assertSame(17_000_000.0, (new PrizeTable($game, 1))->prizeFor(1));
    }
}
