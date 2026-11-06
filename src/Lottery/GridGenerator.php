<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Grid generator built on random_int(), PHP's cryptographically secure
 * random generator (CSPRNG): every combination is strictly equiprobable,
 * no predictable seed.
 */
final class GridGenerator
{
    private const MAX_ATTEMPTS = 10_000;

    public function __construct(private readonly LotteryGame $game)
    {
    }

    public function random(): Grid
    {
        return Grid::of(
            $this->game,
            $this->sample($this->game->mainPool(), $this->game->mainPicked()),
            $this->sample($this->game->bonusPool(), $this->game->bonusPicked()),
        );
    }

    /**
     * Generates $count distinct grids that pass the anti-sharing filter.
     *
     * @return array{grids: list<Grid>, rejected: array<string,int>}
     */
    public function generate(int $count, ?PopularityFilter $filter = null): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('The number of grids must be at least 1.');
        }

        $grids = [];
        $seen = [];
        $rejected = [];
        $attempts = 0;

        while (\count($grids) < $count) {
            if (++$attempts > self::MAX_ATTEMPTS) {
                throw new \RuntimeException('Too many generation attempts: the filter is too restrictive.');
            }

            $grid = $this->random();

            if (null !== $filter) {
                $reasons = $filter->rejectionReasons($grid);
                if ([] !== $reasons) {
                    foreach ($reasons as $reason) {
                        $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
                    }
                    continue;
                }
            }

            $signature = $grid->signature();
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $grids[] = $grid;
        }

        return ['grids' => $grids, 'rejected' => $rejected];
    }

    /**
     * Uniform sampling without replacement of $picked distinct values in [1, $pool].
     *
     * @return list<int>
     */
    private function sample(int $pool, int $picked): array
    {
        $values = [];
        while (\count($values) < $picked) {
            $values[random_int(1, $pool)] = true;
        }

        $values = array_keys($values);
        sort($values);

        return $values;
    }
}
