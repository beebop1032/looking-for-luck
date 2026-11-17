<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\DrawSchedule;
use App\Tests\Fixtures\FiveOfSixtyNineGame;
use PHPUnit\Framework\TestCase;

final class DrawScheduleTest extends TestCase
{
    public function testPreviousDrawIsTheLastScheduledOne(): void
    {
        $game = new EuromillionsGame();
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame('2026-11-13 21:00', DrawSchedule::previousBefore($game, new \DateTimeImmutable('2026-11-17 20:00', $paris))->format('Y-m-d H:i'));
        self::assertSame('2026-11-17 21:00', DrawSchedule::previousBefore($game, new \DateTimeImmutable('2026-11-17 21:00', $paris))->format('Y-m-d H:i'));
    }

    public function testScheduleUsesTheGameTimezone(): void
    {
        $game = new FiveOfSixtyNineGame();

        // Tuesday 17 Nov, 03:00 in Paris is still Monday 21:00 in New York: the draw is tonight there.
        $next = DrawSchedule::nextAfter($game, new \DateTimeImmutable('2026-11-17 03:00', new \DateTimeZone('Europe/Paris')));

        self::assertSame('2026-11-16 22:59', $next->format('Y-m-d H:i'));
        self::assertSame('America/New_York', $next->getTimezone()->getName());
    }

    public function testInvalidDrawTimeIsReported(): void
    {
        $game = new FiveOfSixtyNineGame('9pm');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid draw time "9pm"');

        DrawSchedule::nextAfter($game, new \DateTimeImmutable());
    }
}
