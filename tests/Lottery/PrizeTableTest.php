<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\PrizeTable;
use PHPUnit\Framework\TestCase;

final class PrizeTableTest extends TestCase
{
    private EuromillionsGame $game;
    private PrizeTable $table;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
        $this->table = new PrizeTable($this->game);
    }

    public function testJackpotOdds(): void
    {
        // The official 5+2 odds are 1 in 139,838,160.
        self::assertEqualsWithDelta(1 / 139_838_160, $this->table->probability(5, 2), 1e-15);
    }

    public function testRankMapping(): void
    {
        self::assertSame(1, $this->table->rankFor(5, 2));
        self::assertSame(13, $this->table->rankFor(2, 0));
        self::assertSame(11, $this->table->rankFor(1, 2));
        self::assertNull($this->table->rankFor(1, 1));
        self::assertNull($this->table->rankFor(0, 2));
        self::assertNull($this->table->rankFor(0, 0));
    }

    public function testProbabilitiesSumToOneOverAllOutcomes(): void
    {
        $sum = 0.0;
        for ($main = 0; $main <= 5; ++$main) {
            for ($bonus = 0; $bonus <= 2; ++$bonus) {
                $sum += $this->table->probability($main, $bonus);
            }
        }

        self::assertEqualsWithDelta(1.0, $sum, 1e-12);
    }

    public function testAnyWinProbabilityIsAboutOneInThirteen(): void
    {
        self::assertEqualsWithDelta(1 / 13.0, $this->table->anyWinProbability(), 0.005);
    }

    public function testExpectedPrizeIsBelowTypicalGridPrice(): void
    {
        // The expected prize must remain below the ticket price (€2.50):
        // that is the whole pedagogical point of the tool.
        self::assertLessThan($this->game->defaultTicketPrice(), $this->table->expectedPrizePerGrid());
        self::assertGreaterThan(0.0, $this->table->expectedPrizePerGrid());
    }

    public function testJackpotIsClampedToGameMinimum(): void
    {
        $table = $this->table->withJackpot(1_000.0);

        self::assertSame($this->game->minimumJackpot(), $table->prizeFor(1));
    }
}
