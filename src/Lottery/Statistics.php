<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Descriptive statistics over a draw history: frequencies, gaps (draws since
 * last appearance), frequent pairs, and a chi-squared test of compatibility
 * with pure randomness.
 */
final class Statistics
{
    public function __construct(
        private readonly LotteryGame $game,
        private readonly ChiSquare $chiSquare,
    ) {
    }

    /**
     * @param list<Draw> $draws
     *
     * @return array<int,int> number => times drawn (complete over the main pool)
     */
    public function mainFrequencies(array $draws): array
    {
        return $this->frequencies($draws, $this->game->mainPool(), static fn (Draw $d): array => $d->grid->main);
    }

    /**
     * @param list<Draw> $draws
     *
     * @return array<int,int> bonus number => times drawn (complete over the bonus pool)
     */
    public function bonusFrequencies(array $draws): array
    {
        return $this->frequencies($draws, $this->game->bonusPool(), static fn (Draw $d): array => $d->grid->bonus);
    }

    /**
     * Current gap: number of draws since the last appearance.
     *
     * @param list<Draw> $draws sorted oldest to newest
     *
     * @return array<int,int> number => gap (count($draws) if never drawn)
     */
    public function mainGaps(array $draws): array
    {
        return $this->gaps($draws, $this->game->mainPool(), static fn (Draw $d): array => $d->grid->main);
    }

    /**
     * @param list<Draw> $draws
     *
     * @return array<int,int>
     */
    public function bonusGaps(array $draws): array
    {
        return $this->gaps($draws, $this->game->bonusPool(), static fn (Draw $d): array => $d->grid->bonus);
    }

    /**
     * Most frequent pairs of main numbers.
     *
     * @param list<Draw> $draws
     *
     * @return list<array{pair: array{int,int}, count: int}>
     */
    public function topPairs(array $draws, int $limit = 10): array
    {
        $counts = [];
        foreach ($draws as $draw) {
            $numbers = $draw->grid->main;
            $n = \count($numbers);
            for ($i = 0; $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $key = $numbers[$i].'-'.$numbers[$j];
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        $top = [];
        foreach (\array_slice($counts, 0, $limit, true) as $key => $count) {
            [$a, $b] = array_map(intval(...), explode('-', (string) $key));
            $top[] = ['pair' => [$a, $b], 'count' => $count];
        }

        return $top;
    }

    /**
     * @param array<int,int> $frequencies
     *
     * @return array{statistic: float, degreesOfFreedom: int, pValue: float, compatible: bool}
     */
    public function uniformityTest(array $frequencies): array
    {
        $result = $this->chiSquare->uniformGoodnessOfFit($frequencies);

        return [...$result, 'compatible' => $result['pValue'] >= 0.05];
    }

    /**
     * @param list<Draw> $draws
     * @param callable(Draw): list<int> $values
     *
     * @return array<int,int>
     */
    private function frequencies(array $draws, int $pool, callable $values): array
    {
        $frequencies = array_fill(1, $pool, 0);
        foreach ($draws as $draw) {
            foreach ($values($draw) as $value) {
                ++$frequencies[$value];
            }
        }

        return $frequencies;
    }

    /**
     * @param list<Draw> $draws
     * @param callable(Draw): list<int> $values
     *
     * @return array<int,int>
     */
    private function gaps(array $draws, int $pool, callable $values): array
    {
        $total = \count($draws);
        $lastSeen = array_fill(1, $pool, -1);

        foreach ($draws as $index => $draw) {
            foreach ($values($draw) as $value) {
                $lastSeen[$value] = $index;
            }
        }

        $gaps = [];
        for ($value = 1; $value <= $pool; ++$value) {
            $gaps[$value] = -1 === $lastSeen[$value] ? $total : $total - 1 - $lastSeen[$value];
        }

        return $gaps;
    }
}
