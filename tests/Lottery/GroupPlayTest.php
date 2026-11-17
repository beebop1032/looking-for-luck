<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\GridGenerator;
use App\Lottery\GroupPlay;
use App\Lottery\PrizeTable;
use App\Tests\Fixtures\FiveOfSixtyNineGame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupPlayTest extends TestCase
{
    public function testASimpleGridIsTheSingleTicket(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $result = (new GroupPlay($game, $table))->analyse(5, 2, 2.5);

        self::assertSame(1, $result['combinations']);
        self::assertEqualsWithDelta(1 / 139_838_160, $result['jackpot'], 1e-18);
        self::assertEqualsWithDelta($table->anyWinProbability(), $result['any'], 1e-12);
        foreach ($result['tiers'] as $i => $tier) {
            self::assertEqualsWithDelta($table->ranks()[$i]['probability'], $tier['probability'], 1e-15);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function multipleProvider(): iterable
    {
        // numbers, stars, combinations, jackpot odds (1 in …)
        yield '6 + 2' => [6, 2, 6, 23_306_360];
        yield '7 + 3' => [7, 3, 63, 2_219_653];
        yield '10 + 5' => [10, 5, 2_520, 55_491];
    }

    #[DataProvider('multipleProvider')]
    public function testMultipleGridsCoverMoreCombinations(int $numbers, int $stars, int $combinations, int $odds): void
    {
        $game = new EuromillionsGame();
        $result = (new GroupPlay($game, new PrizeTable($game)))->analyse($numbers, $stars, 2.5);

        self::assertSame($combinations, $result['combinations']);
        self::assertSame($combinations * 2.5, $result['cost']);
        // The jackpot needs all 5 numbers and both stars in the chosen sets.
        self::assertEqualsWithDelta($combinations / 139_838_160, $result['jackpot'], 1e-15);
        self::assertSame($odds, (int) round(1 / $result['jackpot']));
    }

    public function testPlayingTogetherNeverChangesWhatEachEuroIsWorth(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $single = $table->expectedPrizePerGrid() / 2.5;
        $group = new GroupPlay($game, $table);

        foreach ([[5, 2], [6, 3], [8, 4], [10, 12]] as [$numbers, $stars]) {
            self::assertEqualsWithDelta($single, $group->analyse($numbers, $stars, 2.5)['returnRate'], 1e-9);
        }
    }

    public function testWorksForAnyMatrix(): void
    {
        $game = new FiveOfSixtyNineGame();
        $result = (new GroupPlay($game, new PrizeTable($game)))->analyse(6, 2, 2.0);

        self::assertSame(12, $result['combinations']); // C(6,5) × C(2,1)
        self::assertGreaterThan(0.0, $result['any']);
    }

    public function testRejectsImpossibleGrids(): void
    {
        $game = new EuromillionsGame();

        $this->expectException(\InvalidArgumentException::class);
        (new GroupPlay($game, new PrizeTable($game)))->analyse(4, 2, 2.5);
    }

    public function testChancesOverSeveralDraws(): void
    {
        self::assertSame(0.0, GroupPlay::overDraws(0.0, 100));
        self::assertEqualsWithDelta(0.75, GroupPlay::overDraws(0.5, 2), 1e-12);
    }

    public function testMultipleGridIsDrawnWithTheRightSize(): void
    {
        $grid = (new GridGenerator(new EuromillionsGame()))->multiple(7, 3);

        self::assertCount(7, array_unique($grid['main']));
        self::assertCount(3, array_unique($grid['bonus']));
        self::assertLessThanOrEqual(50, max([0, ...$grid['main']]));
        self::assertLessThanOrEqual(12, max([0, ...$grid['bonus']]));
    }
}
