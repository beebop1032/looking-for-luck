<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\GridGenerator;
use App\Lottery\PrizeTable;
use App\Lottery\StrategyLab;
use App\Tests\Fixtures\FiveOfSixtyNineGame;
use PHPUnit\Framework\TestCase;

final class StrategyLabTest extends TestCase
{
    public function testOnlyDrawsUnderTheCurrentRulesAreReplayed(): void
    {
        $game = new EuromillionsGame();
        $lab = new StrategyLab($game, new PrizeTable($game), new GridGenerator($game));

        // 100 draws before the 12-star rules (24 Sept 2016), 80 after.
        $draws = [];
        $date = new \DateTimeImmutable('2015-01-02');
        for ($i = 0; $i < 180; ++$i) {
            $day = $i < 100 ? $date->modify(\sprintf('+%d days', 3 * $i)) : new \DateTimeImmutable(\sprintf('2017-01-03 +%d days', 3 * ($i - 100)));
            $draws[] = new Draw($day, Grid::of($game, [1 + $i % 45, 46, 47, 48, 49], [1, 2 + $i % 7]));
        }

        $result = $lab->run('hot', $draws, 100, 2.5, 20);

        // The 100 older draws are only used to learn: every recent draw is played.
        self::assertSame(80, $result['playedDraws']);
        self::assertGreaterThanOrEqual(new \DateTimeImmutable(EuromillionsGame::STAR_RULES_SINCE), $result['from']);
        self::assertCount(80, $result['plays']);
        self::assertSame($result['plays'][0]['date'], $result['from']);
        self::assertLessThanOrEqual(80, $result['distinctGrids']);
        // Numbers 46-49 and star 1 came out at every draw: the "hot" pick for the next draw.
        self::assertSame([46, 47, 48, 49], \array_slice($result['nextGrid']->main, 1));
        self::assertContains(1, $result['nextGrid']->bonus);
    }

    public function testWithoutRuleChangeTheWarmupIsTheLearningPeriod(): void
    {
        $game = new FiveOfSixtyNineGame('22:59', null);
        $lab = new StrategyLab($game, new PrizeTable($game), new GridGenerator($game));

        $draws = [];
        for ($i = 0; $i < 70; ++$i) {
            $draws[] = new Draw(new \DateTimeImmutable(\sprintf('2020-01-01 +%d days', 3 * $i)), Grid::of($game, [1 + $i % 60, 61, 62, 63, 64], [1 + $i % 26]));
        }

        self::assertSame(50, $lab->run('cold', $draws, 100, 2.0, 20)['playedDraws']);
    }

    public function testRefusesWhenTooFewRecentDraws(): void
    {
        $game = new EuromillionsGame();
        $lab = new StrategyLab($game, new PrizeTable($game), new GridGenerator($game));
        $draws = [new Draw(new \DateTimeImmutable('2010-01-01'), Grid::of($game, [1, 2, 3, 4, 5], [1, 2]))];

        $this->expectException(\InvalidArgumentException::class);
        $lab->run('hot', $draws, 100, 2.5, 10);
    }
}
