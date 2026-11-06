<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Grid;
use PHPUnit\Framework\TestCase;

final class GridTest extends TestCase
{
    private EuromillionsGame $game;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
    }

    public function testNormalizesAndSorts(): void
    {
        $grid = Grid::of($this->game, [44, 4, 23, 29, 37], [9, 2]);

        self::assertSame([4, 23, 29, 37, 44], $grid->main);
        self::assertSame([2, 9], $grid->bonus);
        self::assertSame('04 23 29 37 44 + 02 09', $grid->format());
        self::assertSame('4-23-29-37-44|2-9', $grid->signature());
    }

    public function testRejectsOutOfRangeMainNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Grid::of($this->game, [4, 23, 29, 37, 61], [2, 9]);
    }

    public function testRejectsOutOfRangeBonusNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Grid::of($this->game, [4, 23, 29, 37, 44], [2, 13]);
    }

    public function testRejectsDuplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Grid::of($this->game, [4, 4, 29, 37, 44], [2, 9]);
    }

    public function testMatches(): void
    {
        $a = Grid::of($this->game, [4, 23, 29, 37, 44], [2, 9]);
        $b = Grid::of($this->game, [4, 23, 30, 38, 45], [9, 11]);

        self::assertSame(['main' => 2, 'bonus' => 1], $a->matches($b));
    }
}
