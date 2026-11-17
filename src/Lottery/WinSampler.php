<?php

declare(strict_types=1);

namespace App\Lottery;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fast sampling of the number of wins over N played grids.
 *
 * The number of hits of a tier with probability p over N trials follows a
 * binomial distribution B(N, p). When few hits are expected, we jump straight
 * from one hit to the next through the geometric distribution (exact). When
 * many are expected, the binomial is indistinguishable from a normal
 * distribution, which is sampled in constant time: the cost of a sample never
 * grows with the number of grids, so long simulations stay near-instant.
 *
 * Used for SIMULATION only; real grid generation goes through the CSPRNG
 * (random_int) in GridGenerator.
 */
final class WinSampler
{
    /** Above this many expected hits, the normal approximation is used. */
    private const NORMAL_ABOVE = 50.0;

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

        $mean = $trials * $probability;
        if ($mean > self::NORMAL_ABOVE) {
            $deviation = sqrt($mean * (1.0 - $probability));
            $sample = (int) round($mean + $deviation * $this->standardNormal());

            return max(0, min($trials, $sample));
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
     * Standard normal, by the Box-Muller transform.
     */
    private function standardNormal(): float
    {
        return sqrt(-2.0 * log($this->uniform())) * cos(2.0 * \M_PI * $this->uniform());
    }

    /**
     * Uniform in (0, 1].
     */
    private function uniform(): float
    {
        return $this->randomizer->getInt(1, \PHP_INT_MAX) / \PHP_INT_MAX;
    }
}
