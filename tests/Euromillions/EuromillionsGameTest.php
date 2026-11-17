<?php

declare(strict_types=1);

namespace App\Tests\Euromillions;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\Lottery;
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

        $filtered = Lottery::underCurrentRules($this->game, [$old, $new]);

        self::assertCount(1, $filtered);
        self::assertSame($new, $filtered[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nextDrawProvider(): iterable
    {
        yield 'Monday → Tuesday' => ['2026-11-16 10:00', '2026-11-17 21:00'];
        yield 'Tuesday before the draw → same evening' => ['2026-11-17 20:59', '2026-11-17 21:00'];
        yield 'Tuesday at draw time → Friday' => ['2026-11-17 21:00', '2026-11-20 21:00'];
        yield 'Friday night → next Tuesday' => ['2026-11-20 23:00', '2026-11-24 21:00'];
        yield 'Sunday → Tuesday' => ['2026-11-22 12:00', '2026-11-24 21:00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nextDrawProvider')]
    public function testNextDrawAfter(string $now, string $expected): void
    {
        $paris = new \DateTimeZone('Europe/Paris');

        $next = $this->game->nextDrawAfter(new \DateTimeImmutable($now, $paris));

        self::assertSame($expected, $next->format('Y-m-d H:i'));
        self::assertSame('Europe/Paris', $next->getTimezone()->getName());
    }

    public function testNextDrawIsComputedInParisTime(): void
    {
        // 20:30 UTC on a Tuesday in winter is 21:30 in Paris: the draw is over.
        $next = $this->game->nextDrawAfter(new \DateTimeImmutable('2026-11-17 20:30', new \DateTimeZone('UTC')));

        self::assertSame('2026-11-20 21:00', $next->format('Y-m-d H:i'));
    }
}
