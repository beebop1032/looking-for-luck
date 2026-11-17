<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\DrawSchedule;
use App\Lottery\LotteryGame;
use App\Lottery\PrizeTier;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The EuroMillions matrix: 5 numbers out of 50, 2 stars out of 12,
 * two draws a week, 13 prize tiers.
 */
#[AsAlias(LotteryGame::class)]
final class EuromillionsGame implements LotteryGame
{
    /**
     * Since 2016-09-24 the stars range from 1 to 12 (9 before 2011, 11 from
     * 2011 to 2016). Any star equiprobability test must therefore be limited
     * to draws held under constant rules.
     */
    public const STAR_RULES_SINCE = '2016-09-24';

    public function name(): string
    {
        return 'EuroMillions';
    }

    public function mainPool(): int
    {
        return 50;
    }

    public function mainPicked(): int
    {
        return 5;
    }

    public function bonusPool(): int
    {
        return 12;
    }

    public function bonusPicked(): int
    {
        return 2;
    }

    public function bonusLabel(): string
    {
        return 'Stars';
    }

    public function drawsPerWeek(): int
    {
        return 2; // Tuesday and Friday
    }

    public function drawDays(): array
    {
        return [2, 5]; // Tuesday and Friday
    }

    public function drawTime(): string
    {
        return '21:00';
    }

    public function drawTimezone(): string
    {
        return 'Europe/Paris';
    }

    public function currency(): string
    {
        return 'EUR';
    }

    public function currentRulesSince(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::STAR_RULES_SINCE);
    }

    public function slipColumns(): int
    {
        return 5;
    }

    public function defaultTicketPrice(): float
    {
        return 2.5;
    }

    public function defaultJackpot(): float
    {
        return 50_000_000.0;
    }

    public function minimumJackpot(): float
    {
        return 17_000_000.0;
    }

    public function maximumJackpot(): float
    {
        return 250_000_000.0;
    }

    public function prizeTiers(): array
    {
        // Average prizes are indicative: only the lowest tiers are roughly
        // fixed, the others vary at every draw (pari-mutuel pools).
        return [
            new PrizeTier(1, 5, 2, $this->defaultJackpot()),
            new PrizeTier(2, 5, 1, 400_000.0),
            new PrizeTier(3, 5, 0, 60_000.0),
            new PrizeTier(4, 4, 2, 2_500.0),
            new PrizeTier(5, 4, 1, 150.0),
            new PrizeTier(6, 3, 2, 100.0),
            new PrizeTier(7, 4, 0, 60.0),
            new PrizeTier(8, 2, 2, 20.0),
            new PrizeTier(9, 3, 1, 15.0),
            new PrizeTier(10, 3, 0, 13.0),
            new PrizeTier(11, 1, 2, 10.0),
            new PrizeTier(12, 2, 1, 8.0),
            new PrizeTier(13, 2, 0, 4.0),
        ];
    }

    /**
     * The first draw strictly after the given moment (in Paris time).
     */
    public function nextDrawAfter(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return DrawSchedule::nextAfter($this, $moment);
    }
}
