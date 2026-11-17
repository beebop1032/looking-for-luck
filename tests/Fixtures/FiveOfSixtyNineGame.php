<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Lottery\LotteryGame;
use App\Lottery\PrizeTier;

/**
 * A Powerball-like matrix (5/69 + 1/26, three draws a week in New York),
 * proving the engine is not tied to EuroMillions.
 */
final class FiveOfSixtyNineGame implements LotteryGame
{
    public function __construct(
        private readonly string $drawTime = '22:59',
        private readonly ?string $rulesSince = '2015-10-07',
    ) {
    }

    public function name(): string
    {
        return 'Test Ball';
    }

    public function mainPool(): int
    {
        return 69;
    }

    public function mainPicked(): int
    {
        return 5;
    }

    public function bonusPool(): int
    {
        return 26;
    }

    public function bonusPicked(): int
    {
        return 1;
    }

    public function bonusLabel(): string
    {
        return 'Bonus ball';
    }

    public function drawsPerWeek(): int
    {
        return 3;
    }

    public function drawDays(): array
    {
        return [1, 3, 6];
    }

    public function drawTime(): string
    {
        return $this->drawTime;
    }

    public function drawTimezone(): string
    {
        return 'America/New_York';
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function currentRulesSince(): ?\DateTimeImmutable
    {
        return null === $this->rulesSince ? null : new \DateTimeImmutable($this->rulesSince);
    }

    public function slipColumns(): int
    {
        return 10;
    }

    public function defaultTicketPrice(): float
    {
        return 2.0;
    }

    public function defaultJackpot(): float
    {
        return 100_000_000.0;
    }

    public function minimumJackpot(): float
    {
        return 20_000_000.0;
    }

    public function maximumJackpot(): float
    {
        return 2_000_000_000.0;
    }

    public function prizeTiers(): array
    {
        return [
            new PrizeTier(1, 5, 1, $this->defaultJackpot()),
            new PrizeTier(2, 5, 0, 1_000_000.0),
            new PrizeTier(3, 4, 1, 50_000.0),
            new PrizeTier(4, 4, 0, 100.0),
            new PrizeTier(5, 3, 1, 100.0),
            new PrizeTier(6, 3, 0, 7.0),
            new PrizeTier(7, 2, 1, 7.0),
            new PrizeTier(8, 1, 1, 4.0),
            new PrizeTier(9, 0, 1, 4.0),
        ];
    }
}
