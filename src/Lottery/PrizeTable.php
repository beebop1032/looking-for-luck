<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Prize maths for a lottery game.
 *
 * Probabilities are exact (hypergeometric law over the game's matrix). Prize
 * amounts come from the game's tier definitions and are indicative averages —
 * pari-mutuel tiers vary at every draw; the jackpot is configurable.
 */
final class PrizeTable
{
    private readonly float $jackpot;

    public function __construct(
        private readonly LotteryGame $game,
        ?float $jackpot = null,
    ) {
        $this->jackpot = max($game->minimumJackpot(), $jackpot ?? $game->defaultJackpot());
    }

    public function withJackpot(float $jackpot): self
    {
        return new self($this->game, $jackpot);
    }

    /**
     * @return list<array{rank:int, main:int, bonus:int, probability:float, odds:float, averagePrize:float}>
     */
    public function ranks(): array
    {
        $ranks = [];
        foreach ($this->game->prizeTiers() as $tier) {
            $probability = $this->probability($tier->mainMatches, $tier->bonusMatches);
            $ranks[] = [
                'rank' => $tier->rank,
                'main' => $tier->mainMatches,
                'bonus' => $tier->bonusMatches,
                'probability' => $probability,
                'odds' => 1.0 / $probability,
                'averagePrize' => 1 === $tier->rank ? $this->jackpot : $tier->averagePrize,
            ];
        }

        return $ranks;
    }

    /**
     * Prize tier for a count of matched main / bonus numbers, or null if lost.
     */
    public function rankFor(int $matchedMain, int $matchedBonus): ?int
    {
        foreach ($this->game->prizeTiers() as $tier) {
            if ($tier->mainMatches === $matchedMain && $tier->bonusMatches === $matchedBonus) {
                return $tier->rank;
            }
        }

        return null;
    }

    public function prizeFor(int $rank): float
    {
        foreach ($this->game->prizeTiers() as $tier) {
            if ($tier->rank === $rank) {
                return 1 === $rank ? $this->jackpot : $tier->averagePrize;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Unknown prize tier: %d.', $rank));
    }

    /**
     * Exact probability of matching exactly k main numbers and j bonus numbers.
     */
    public function probability(int $matchedMain, int $matchedBonus): float
    {
        $pMain = self::choose($this->game->mainPicked(), $matchedMain)
            * self::choose($this->game->mainPool() - $this->game->mainPicked(), $this->game->mainPicked() - $matchedMain)
            / self::choose($this->game->mainPool(), $this->game->mainPicked());

        $pBonus = self::choose($this->game->bonusPicked(), $matchedBonus)
            * self::choose($this->game->bonusPool() - $this->game->bonusPicked(), $this->game->bonusPicked() - $matchedBonus)
            / self::choose($this->game->bonusPool(), $this->game->bonusPicked());

        return $pMain * $pBonus;
    }

    /**
     * Probability of winning anything (any tier).
     */
    public function anyWinProbability(): float
    {
        return array_sum(array_column($this->ranks(), 'probability'));
    }

    /**
     * Expected prize per grid (before deducting the ticket price).
     */
    public function expectedPrizePerGrid(): float
    {
        $expected = 0.0;
        foreach ($this->ranks() as $rank) {
            $expected += $rank['probability'] * $rank['averagePrize'];
        }

        return $expected;
    }

    private static function choose(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return 0.0;
        }

        $result = 1.0;
        for ($i = 1; $i <= $k; ++$i) {
            $result = $result * ($n - $k + $i) / $i;
        }

        return $result;
    }
}
