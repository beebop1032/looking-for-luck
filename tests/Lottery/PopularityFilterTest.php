<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\PopularityFilter;
use PHPUnit\Framework\TestCase;

final class PopularityFilterTest extends TestCase
{
    private EuromillionsGame $game;
    private PopularityFilter $filter;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
        $this->filter = new PopularityFilter($this->game);
    }

    /**
     * @param list<int> $main
     */
    private function grid(array $main): Grid
    {
        return Grid::of($this->game, $main, [1, 2]);
    }

    public function testRejectsAllBirthdayNumbers(): void
    {
        self::assertTrue($this->filter->isPopular($this->grid([3, 8, 17, 24, 31])));
    }

    public function testAcceptsGridWithHighNumber(): void
    {
        self::assertSame([], $this->filter->rejectionReasons($this->grid([3, 8, 17, 24, 47])));
    }

    public function testRejectsArithmeticSequence(): void
    {
        self::assertTrue($this->filter->isPopular($this->grid([5, 10, 15, 20, 25]))); // multiples too
        self::assertTrue($this->filter->isPopular($this->grid([7, 14, 21, 28, 35])));
        self::assertTrue($this->filter->isPopular($this->grid([1, 12, 23, 34, 45])));
    }

    public function testRejectsConsecutiveRun(): void
    {
        self::assertTrue($this->filter->isPopular($this->grid([21, 22, 23, 24, 37])));
    }

    public function testRejectsFullColumnOnPaperGrid(): void
    {
        // A column of the paper slip (5 columns): step of 5.
        self::assertTrue($this->filter->isPopular($this->grid([2, 7, 17, 32, 47])));
    }

    public function testRejectsCommonMultiples(): void
    {
        self::assertTrue($this->filter->isPopular($this->grid([6, 12, 24, 36, 48]))); // multiples of 6
        self::assertTrue($this->filter->isPopular($this->grid([10, 20, 30, 40, 50]))); // multiples of 10 (and a sequence)
    }

    public function testRejectsAlreadyDrawnCombination(): void
    {
        $history = [
            new Draw(new \DateTimeImmutable('2020-01-03'), Grid::of($this->game, [4, 13, 26, 39, 47], [2, 9])),
        ];
        $filter = $this->filter->withHistory($history);

        self::assertTrue($filter->isPopular($this->grid([4, 13, 26, 39, 47])));
        self::assertSame([], $filter->rejectionReasons($this->grid([4, 13, 26, 39, 46])));
    }
}
