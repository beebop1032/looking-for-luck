<?php

declare(strict_types=1);

namespace App\Tests\Euromillions;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Grid;
use PHPUnit\Framework\TestCase;

final class EuromillionsGameTest extends TestCase
{
    private EuromillionsGame $game;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
    }

    public function testMatrix(): void
    {
        self::assertSame(50, $this->game->mainPool());
        self::assertSame(5, $this->game->mainPicked());
        self::assertSame(12, $this->game->bonusPool());
        self::assertSame(2, $this->game->bonusPicked());
        self::assertCount(13, $this->game->prizeTiers());
    }

    public function testTiersAreUniqueAndOrdered(): void
    {
        $ranks = array_map(static fn ($tier): int => $tier->rank, $this->game->prizeTiers());
        $combos = array_map(static fn ($tier): string => $tier->mainMatches.'+'.$tier->bonusMatches, $this->game->prizeTiers());

        self::assertSame(range(1, 13), $ranks);
        self::assertSame($combos, array_values(array_unique($combos)));
    }

    public function testStarRuleFilter(): void
    {
        $old = new Draw(new \DateTimeImmutable('2010-05-01'), Grid::of($this->game, [1, 2, 3, 4, 5], [1, 2]));
        $new = new Draw(new \DateTimeImmutable('2020-05-01'), Grid::of($this->game, [1, 2, 3, 4, 5], [1, 2]));

        $filtered = $this->game->drawsUnderCurrentStarRules([$old, $new]);

        self::assertCount(1, $filtered);
        self::assertSame($new, $filtered[0]);
    }
}
