<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\ChiSquare;
use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\Statistics;
use PHPUnit\Framework\TestCase;

final class StatisticsTest extends TestCase
{
    private EuromillionsGame $game;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->game = new EuromillionsGame();
        $this->statistics = new Statistics($this->game, new ChiSquare());
    }

    /**
     * @return list<Draw>
     */
    private function draws(): array
    {
        return [
            $this->draw('2026-01-02', [1, 2, 3, 4, 5], [1, 2]),
            $this->draw('2026-01-06', [1, 2, 10, 20, 30], [1, 3]),
            $this->draw('2026-01-09', [1, 7, 10, 40, 50], [2, 3]),
        ];
    }

    /**
     * @param list<int> $main
     * @param list<int> $bonus
     */
    private function draw(string $date, array $main, array $bonus): Draw
    {
        return new Draw(new \DateTimeImmutable($date), Grid::of($this->game, $main, $bonus));
    }

    public function testMainFrequencies(): void
    {
        $frequencies = $this->statistics->mainFrequencies($this->draws());

        self::assertSame(3, $frequencies[1]);
        self::assertSame(2, $frequencies[2]);
        self::assertSame(2, $frequencies[10]);
        self::assertSame(0, $frequencies[6]);
        self::assertCount(50, $frequencies);
        self::assertSame(15, array_sum($frequencies));
    }

    public function testGaps(): void
    {
        $gaps = $this->statistics->mainGaps($this->draws());

        self::assertSame(0, $gaps[1], 'Drawn at the latest draw.');
        self::assertSame(1, $gaps[2], 'Drawn at the second-to-last draw.');
        self::assertSame(2, $gaps[3], 'Drawn two draws ago.');
        self::assertSame(3, $gaps[6], 'Never drawn: gap = number of draws.');
    }

    public function testTopPairs(): void
    {
        $pairs = $this->statistics->topPairs($this->draws(), 3);

        self::assertSame([1, 2], $pairs[0]['pair']);
        self::assertSame(2, $pairs[0]['count']);
    }
}
