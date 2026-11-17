<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\GridGenerator;
use App\Lottery\PopularityFilter;
use PHPUnit\Framework\TestCase;

final class GridGeneratorTest extends TestCase
{
    private EuromillionsGame $game;
    private GridGenerator $generator;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
        $this->generator = new GridGenerator($this->game);
    }

    public function testRandomGridIsValid(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $grid = $this->generator->random();

            // Grid::of() already guarantees cardinality, uniqueness, order and
            // ranges — here we only confirm the generator stays in the pools.
            self::assertCount($this->game->mainPicked(), $grid->main);
            self::assertCount($this->game->bonusPicked(), $grid->bonus);
            self::assertGreaterThanOrEqual(1, min($grid->main));
            self::assertLessThanOrEqual($this->game->mainPool(), max($grid->main));
            self::assertGreaterThanOrEqual(1, min($grid->bonus));
            self::assertLessThanOrEqual($this->game->bonusPool(), max($grid->bonus));
        }
    }

    public function testGeneratedGridsAreDistinctAndPassTheFilter(): void
    {
        $filter = new PopularityFilter($this->game);
        $result = $this->generator->generate(20, $filter);

        self::assertCount(20, $result['grids']);

        $signatures = [];
        foreach ($result['grids'] as $grid) {
            self::assertSame([], $filter->rejectionReasons($grid));
            $signatures[] = $grid->signature();
        }

        self::assertSame($signatures, array_values(array_unique($signatures)));
    }

    public function testRejectsInvalidCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generate(0);
    }

    public function testKeepsTheRejectedGridsWithTheirReasons(): void
    {
        // Reject every grid made only of numbers up to 31 ("birthday" grids):
        // frequent enough that some get rejected over a few hundred grids.
        $result = $this->generator->generate(300, new PopularityFilter($this->game));

        self::assertGreaterThan(0, $result['rejectedTotal']);
        self::assertLessThanOrEqual(30, \count($result['rejectedGrids']));
        self::assertSame(min(30, $result['rejectedTotal']), \count($result['rejectedGrids']));
        foreach ($result['rejectedGrids'] as $rejected) {
            self::assertNotSame([], $rejected['reasons']);
            self::assertTrue((new PopularityFilter($this->game))->isPopular($rejected['grid']));
        }
    }
}
