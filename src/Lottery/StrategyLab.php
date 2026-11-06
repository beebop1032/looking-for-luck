<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Strategy lab: replays a strategy ("hot", "cold", "overdue", random) over
 * the whole history — using at each draw only the information available
 * BEFORE that draw — then compares it against a cohort of purely random
 * players.
 */
final class StrategyLab
{
    public const STRATEGIES = ['hot', 'cold', 'overdue', 'random'];

    public const STRATEGY_LABELS = [
        'hot' => 'Hot numbers (most frequently drawn)',
        'cold' => 'Cold numbers (least frequently drawn)',
        'overdue' => 'Overdue numbers (longest absence)',
        'random' => 'Random grid',
    ];

    public function __construct(
        private readonly LotteryGame $game,
        private readonly PrizeTable $prizeTable,
        private readonly GridGenerator $generator,
    ) {
    }

    /**
     * @param list<Draw> $draws sorted oldest to newest
     *
     * @return array{
     *     strategy: string,
     *     playedDraws: int,
     *     cost: float,
     *     winnings: float,
     *     net: float,
     *     winsByRank: array<int,int>,
     *     cohort: array{players:int, median: float, p5: float, p95: float, best: float, worst: float},
     *     beatenPlayers: int,
     *     percentile: float
     * }
     */
    public function run(
        string $strategy,
        array $draws,
        int $players = 10_000,
        float $gridPrice = 2.5,
        int $warmup = 50,
    ): array {
        if (!\in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown strategy "%s". Choices: %s.', $strategy, implode(', ', self::STRATEGIES)));
        }
        if (\count($draws) <= $warmup + 10) {
            throw new \InvalidArgumentException(\sprintf('Insufficient history: %d draws for a warm-up of %d.', \count($draws), $warmup));
        }

        [$winnings, $winsByRank, $playedDraws] = $this->replay($strategy, $draws, $warmup);
        $cost = $playedDraws * $gridPrice;
        $net = $winnings - $cost;

        $cohortNets = $this->simulateRandomCohort($players, $playedDraws, $gridPrice);
        sort($cohortNets);
        if ([] === $cohortNets) {
            throw new \LogicException('No random players were simulated.');
        }

        $beaten = 0;
        foreach ($cohortNets as $cohortNet) {
            if ($cohortNet >= $net) {
                break;
            }
            ++$beaten;
        }

        return [
            'strategy' => $strategy,
            'playedDraws' => $playedDraws,
            'cost' => $cost,
            'winnings' => $winnings,
            'net' => $net,
            'winsByRank' => $winsByRank,
            'cohort' => [
                'players' => $players,
                'median' => Percentile::of($cohortNets, 50.0),
                'p5' => Percentile::of($cohortNets, 5.0),
                'p95' => Percentile::of($cohortNets, 95.0),
                'best' => $cohortNets[array_key_last($cohortNets)],
                'worst' => $cohortNets[0],
            ],
            'beatenPlayers' => $beaten,
            'percentile' => 100.0 * $beaten / $players,
        ];
    }

    /**
     * @param list<Draw> $draws
     *
     * @return array{0: float, 1: array<int,int>, 2: int}
     */
    private function replay(string $strategy, array $draws, int $warmup): array
    {
        $mainPool = $this->game->mainPool();
        $bonusPool = $this->game->bonusPool();

        $mainFrequency = array_fill(1, $mainPool, 0);
        $bonusFrequency = array_fill(1, $bonusPool, 0);
        $mainLastSeen = array_fill(1, $mainPool, -1);
        $bonusLastSeen = array_fill(1, $bonusPool, -1);

        $winnings = 0.0;
        $winsByRank = [];
        $playedDraws = 0;

        foreach ($draws as $index => $draw) {
            if ($index >= $warmup) {
                $played = $this->pickGrid($strategy, $index, $mainFrequency, $bonusFrequency, $mainLastSeen, $bonusLastSeen);
                $matches = $played->matches($draw->grid);

                $rank = $this->prizeTable->rankFor($matches['main'], $matches['bonus']);
                if (null !== $rank) {
                    $winnings += $this->prizeTable->prizeFor($rank);
                    $winsByRank[$rank] = ($winsByRank[$rank] ?? 0) + 1;
                }
                ++$playedDraws;
            }

            foreach ($draw->grid->main as $number) {
                ++$mainFrequency[$number];
                $mainLastSeen[$number] = $index;
            }
            foreach ($draw->grid->bonus as $bonus) {
                ++$bonusFrequency[$bonus];
                $bonusLastSeen[$bonus] = $index;
            }
        }

        ksort($winsByRank);

        return [$winnings, $winsByRank, $playedDraws];
    }

    /**
     * @param array<int,int> $mainFrequency
     * @param array<int,int> $bonusFrequency
     * @param array<int,int> $mainLastSeen
     * @param array<int,int> $bonusLastSeen
     */
    private function pickGrid(
        string $strategy,
        int $currentIndex,
        array $mainFrequency,
        array $bonusFrequency,
        array $mainLastSeen,
        array $bonusLastSeen,
    ): Grid {
        if ('random' === $strategy) {
            return $this->generator->random();
        }

        $mainScores = match ($strategy) {
            'hot' => $mainFrequency,
            'cold' => array_map(static fn (int $f): int => -$f, $mainFrequency),
            default => array_map(static fn (int $last): int => $currentIndex - $last, $mainLastSeen),
        };
        $bonusScores = match ($strategy) {
            'hot' => $bonusFrequency,
            'cold' => array_map(static fn (int $f): int => -$f, $bonusFrequency),
            default => array_map(static fn (int $last): int => $currentIndex - $last, $bonusLastSeen),
        };

        return Grid::of(
            $this->game,
            $this->topKeys($mainScores, $this->game->mainPicked()),
            $this->topKeys($bonusScores, $this->game->bonusPicked()),
        );
    }

    /**
     * The $count keys with the highest scores (ties: lowest number first).
     *
     * @param array<int,int> $scores
     *
     * @return list<int>
     */
    private function topKeys(array $scores, int $count): array
    {
        uksort($scores, static fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));

        $keys = \array_slice(array_keys($scores), 0, $count);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<float>
     */
    private function simulateRandomCohort(int $players, int $drawsPlayed, float $gridPrice): array
    {
        $sampler = new WinSampler();
        $ranks = $this->prizeTable->ranks();
        $cost = $drawsPlayed * $gridPrice;

        $nets = [];
        for ($player = 0; $player < $players; ++$player) {
            $winnings = 0.0;
            foreach ($ranks as $rank) {
                $wins = $sampler->sampleWins($drawsPlayed, $rank['probability']);
                if ($wins > 0) {
                    $winnings += $wins * $rank['averagePrize'];
                }
            }
            $nets[] = $winnings - $cost;
        }

        return $nets;
    }
}
