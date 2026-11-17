<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Budget simulator: Monte Carlo over N years of play.
 *
 * Each trajectory simulates every grid played over the period and reports the
 * distribution of the net result (median, percentiles), along with the
 * analytic expected loss.
 */
final class BudgetSimulator
{
    public function __construct(
        private readonly LotteryGame $game,
        private readonly PrizeTable $prizeTable,
    ) {
    }

    /**
     * @return array{
     *     gridsPlayed: int,
     *     totalCost: float,
     *     expectedPrizePerGrid: float,
     *     expectedNet: float,
     *     median: float,
     *     percentiles: array<string,float>,
     *     wonBack: array{median: float, p5: float, p95: float},
     *     probabilityPositive: float,
     *     best: float,
     *     worst: float,
     *     simulations: int
     * }
     */
    public function simulate(
        int $years,
        int $gridsPerDraw = 1,
        float $gridPrice = 2.5,
        int $simulations = 10_000,
        ?PrizeTable $prizeTable = null,
    ): array {
        if ($years < 1 || $years > 80) {
            throw new \InvalidArgumentException('The number of years must be between 1 and 80.');
        }
        if ($gridsPerDraw < 1 || $gridsPerDraw > 100) {
            throw new \InvalidArgumentException('The number of grids per draw must be between 1 and 100.');
        }
        if ($gridPrice <= 0) {
            throw new \InvalidArgumentException('The grid price must be positive.');
        }

        $table = $prizeTable ?? $this->prizeTable;
        $gridsPlayed = $years * 52 * $this->game->drawsPerWeek() * $gridsPerDraw;
        $totalCost = $gridsPlayed * $gridPrice;
        $ranks = $table->ranks();

        $sampler = new WinSampler();
        $nets = [];
        $positive = 0;

        for ($i = 0; $i < $simulations; ++$i) {
            $winnings = 0.0;
            foreach ($ranks as $rank) {
                $wins = $sampler->sampleWins($gridsPlayed, $rank['probability']);
                if ($wins > 0) {
                    $winnings += $wins * $rank['averagePrize'];
                }
            }
            $net = $winnings - $totalCost;
            $nets[] = $net;
            if ($net > 0) {
                ++$positive;
            }
        }

        sort($nets);
        if ([] === $nets) {
            throw new \LogicException('No trajectories were simulated.');
        }

        // Prizes won back, easier to read than a net result (and never negative).
        $wonBack = static fn (float $percentile): float => max(0.0, Percentile::of($nets, $percentile) + $totalCost);

        return [
            'gridsPlayed' => $gridsPlayed,
            'totalCost' => $totalCost,
            'expectedPrizePerGrid' => $table->expectedPrizePerGrid(),
            'expectedNet' => $gridsPlayed * ($table->expectedPrizePerGrid() - $gridPrice),
            'median' => Percentile::of($nets, 50.0),
            'percentiles' => [
                'p5' => Percentile::of($nets, 5.0),
                'p25' => Percentile::of($nets, 25.0),
                'p75' => Percentile::of($nets, 75.0),
                'p95' => Percentile::of($nets, 95.0),
            ],
            'wonBack' => ['median' => $wonBack(50.0), 'p5' => $wonBack(5.0), 'p95' => $wonBack(95.0)],
            'probabilityPositive' => $positive / $simulations,
            'best' => $nets[array_key_last($nets)],
            'worst' => $nets[0],
            'simulations' => $simulations,
        ];
    }

    /**
     * Chances over a whole period of play: for each prize tier, the
     * probability of winning it at least once over $gridsPlayed grids, and
     * how many times it is won on average. Exact, no simulation:
     * P(at least once) = 1 − (1 − p)^n, computed with log1p for tiny p.
     *
     * @return array{
     *     any: array{atLeastOnce: float, expectedWins: float, probabilityPerGrid: float},
     *     tiers: list<array{rank: int, main: int, bonus: int, averagePrize: float, probabilityPerGrid: float, atLeastOnce: float, expectedWins: float}>
     * }
     */
    public function chances(int $gridsPlayed, ?PrizeTable $prizeTable = null): array
    {
        if ($gridsPlayed < 0) {
            throw new \InvalidArgumentException('The number of grids cannot be negative.');
        }

        $table = $prizeTable ?? $this->prizeTable;
        $atLeastOnce = static fn (float $p): float => $p >= 1.0 ? 1.0 : 1.0 - exp($gridsPlayed * log1p(-$p));

        $tiers = [];
        foreach ($table->ranks() as $rank) {
            $tiers[] = [
                'rank' => $rank['rank'],
                'main' => $rank['main'],
                'bonus' => $rank['bonus'],
                'averagePrize' => $rank['averagePrize'],
                'probabilityPerGrid' => $rank['probability'],
                'atLeastOnce' => $atLeastOnce($rank['probability']),
                'expectedWins' => $gridsPlayed * $rank['probability'],
            ];
        }

        $any = $table->anyWinProbability();

        return [
            'any' => ['atLeastOnce' => $atLeastOnce($any), 'expectedWins' => $gridsPlayed * $any, 'probabilityPerGrid' => $any],
            'tiers' => $tiers,
        ];
    }
}
