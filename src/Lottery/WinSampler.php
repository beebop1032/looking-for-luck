<?php

declare(strict_types=1);

namespace App\Lottery;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fast sampling of the number of wins over N played grids.
 *
 * The number of hits of a tier with probability p over N trials follows a
 * binomial distribution B(N, p). For tiny p we jump straight from one hit to
 * the next through the geometric distribution, which makes the Monte Carlo
 * simulations (10,000 players / 10,000 trajectories) near-instant.
 *
 * Used for SIMULATION only; real grid generation goes through the CSPRNG
 * (random_int) in GridGenerator.
 */
final class WinSampler
{
    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Xoshiro256StarStar());
    }

    public function sampleWins(int $trials, float $probability): int
    {
        if ($trials <= 0 || $probability <= 0.0) {
            return 0;
        }
        if ($probability >= 1.0) {
            return $trials;
        }

        $logQ = log(1.0 - $probability);
        $wins = 0;
        $position = 0;

        while (true) {
            $u = $this->uniform();
            $skip = (int) floor(log($u) / $logQ);
            $position += $skip + 1;
            if ($position > $trials) {
                return $wins;
            }
            ++$wins;
        }
    }

    /**
     * Uniform in (0, 1].
     */
    private function uniform(): float
    {
        return $this->randomizer->getInt(1, \PHP_INT_MAX) / \PHP_INT_MAX;
    }
}
