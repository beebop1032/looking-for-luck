<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Playing as a group: several people share the cost of a multiple grid
 * (more numbers than a simple grid). Everything is computed exactly, from
 * the hypergeometric distribution of how many drawn numbers fall in the
 * chosen set — no simulation.
 *
 * Honest by construction: a multiple grid is just every simple grid it
 * contains, so each euro staked has exactly the same expected value as when
 * playing alone. The group wins more often, and shares every prize.
 */
final class GroupPlay
{
    public function __construct(
        private readonly LotteryGame $game,
        private readonly PrizeTable $prizeTable,
    ) {
    }

    /**
     * @return array{
     *     combinations: int,
     *     cost: float,
     *     jackpot: float,
     *     any: float,
     *     expectedPrize: float,
     *     returnRate: float,
     *     tiers: list<array{rank: int, main: int, bonus: int, averagePrize: float, probability: float}>
     * } per draw: probabilities that the group wins (at least once) each tier,
     *   anything, or the jackpot; expected prizes; prizes returned per euro staked
     */
    public function analyse(int $mainChosen, int $bonusChosen, float $gridPrice): array
    {
        $g = $this->game;
        $n = $g->mainPicked();
        $b = $g->bonusPicked();
        if ($mainChosen < $n || $mainChosen > $g->mainPool() || $bonusChosen < $b || $bonusChosen > $g->bonusPool()) {
            throw new \InvalidArgumentException(\sprintf('Choose %d to %d numbers and %d to %d bonus numbers.', $n, $g->mainPool(), $b, $g->bonusPool()));
        }
        if ($gridPrice <= 0) {
            throw new \InvalidArgumentException('The grid price must be positive.');
        }

        $combinations = (int) round(PrizeTable::choose($mainChosen, $n) * PrizeTable::choose($bonusChosen, $b));

        // How many of the drawn numbers (k) and bonus numbers (j) land in the chosen sets.
        $mainHits = $this->hits($g->mainPool(), $n, $mainChosen);
        $bonusHits = $this->hits($g->bonusPool(), $b, $bonusChosen);

        $ranks = $this->prizeTable->ranks();
        $index = [];
        foreach ($ranks as $i => $rank) {
            $index[$rank['rank']] = $i;
        }
        $tierProbability = array_fill(0, \count($ranks), 0.0);
        $any = 0.0;
        $expected = 0.0;

        foreach ($mainHits as $k => $pk) {
            foreach ($bonusHits as $j => $pj) {
                $p = $pk * $pj;
                if ($p <= 0.0) {
                    continue;
                }

                $winnings = 0.0;
                foreach ($this->prizeTable->ticketWins($mainChosen, $bonusChosen, $k, $j) as $rank => $count) {
                    $tierProbability[$index[$rank]] += $p;
                    $winnings += $count * $ranks[$index[$rank]]['averagePrize'];
                }

                if ($winnings > 0) {
                    $any += $p;
                    $expected += $p * $winnings;
                }
            }
        }

        $cost = $combinations * $gridPrice;
        $tiers = [];
        foreach ($ranks as $i => $rank) {
            $tiers[] = [
                'rank' => $rank['rank'],
                'main' => $rank['main'],
                'bonus' => $rank['bonus'],
                'averagePrize' => $rank['averagePrize'],
                'probability' => $tierProbability[$i],
            ];
        }

        return [
            'combinations' => $combinations,
            'cost' => $cost,
            'jackpot' => $tierProbability[0],
            'any' => $any,
            'expectedPrize' => $expected,
            'returnRate' => $expected / $cost,
            'tiers' => $tiers,
        ];
    }

    /**
     * A group playing $tickets tickets of the same size at each draw (e.g.
     * 10 friends, 10 simple grids). Assumes the tickets share no simple grid:
     * the jackpot chance is then exact; the other chances treat the tickets
     * as independent, a very close approximation.
     *
     * @return array{
     *     tickets: int,
     *     combinations: int,
     *     cost: float,
     *     jackpot: float,
     *     any: float,
     *     expectedPrize: float,
     *     returnRate: float,
     *     tiers: list<array{rank: int, main: int, bonus: int, averagePrize: float, probability: float}>,
     *     perTicket: int
     * }
     */
    public function group(int $tickets, int $mainChosen, int $bonusChosen, float $gridPrice): array
    {
        if ($tickets < 1) {
            throw new \InvalidArgumentException('A group plays at least one ticket.');
        }

        $one = $this->analyse($mainChosen, $bonusChosen, $gridPrice);
        $tiers = $one['tiers'];
        foreach ($tiers as $i => $tier) {
            $tiers[$i]['probability'] = 0 === $i ? min(1.0, $tickets * $tier['probability']) : self::overDraws($tier['probability'], $tickets);
        }

        return [
            'tickets' => $tickets,
            'combinations' => $tickets * $one['combinations'],
            'cost' => $tickets * $one['cost'],
            'jackpot' => min(1.0, $tickets * $one['jackpot']),
            'any' => self::overDraws($one['any'], $tickets),
            'expectedPrize' => $tickets * $one['expectedPrize'],
            'returnRate' => $one['returnRate'],
            'tiers' => $tiers,
            'perTicket' => $one['combinations'],
        ];
    }

    /**
     * Probability that something happening with $perDraw chance at each draw
     * happens at least once over $draws draws.
     */
    public static function overDraws(float $perDraw, int $draws): float
    {
        return $perDraw >= 1.0 ? 1.0 : 1.0 - exp($draws * log1p(-$perDraw));
    }

    /**
     * P(exactly k of the $picked drawn numbers are among the $chosen ones).
     *
     * @return array<int, float>
     */
    private function hits(int $pool, int $picked, int $chosen): array
    {
        $total = PrizeTable::choose($pool, $picked);
        $p = [];
        for ($k = 0; $k <= $picked; ++$k) {
            $p[$k] = PrizeTable::choose($chosen, $k) * PrizeTable::choose($pool - $chosen, $picked - $k) / $total;
        }

        return $p;
    }
}
