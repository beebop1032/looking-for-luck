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
            'probabilityPositive' => $positive / $simulations,
            'best' => $nets[array_key_last($nets)],
            'worst' => $nets[0],
            'simulations' => $simulations,
        ];
    }
}
