<?php

declare(strict_types=1);

namespace App\Lottery;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "My grids": local storage (JSON in the lottery's data directory) and checking
 * against draws.
 *
 * Security: atomic writes, content is systematically revalidated on read
 * (ranges, types) — a hand-edited file cannot inject invalid data into the
 * application.
 *
 * Concurrency: the web interface and the scheduled sync both write the file,
 * so every read-modify-write holds an exclusive lock for its whole duration.
 */
final class GridStore
{
    public function __construct(
        private readonly LotteryGame $game,
        #[Autowire('%app.data_dir%')]
        private readonly string $dataDir,
    ) {
    }

    /**
     * @return list<SavedGrid>
     */
    public function all(): array
    {
        $file = $this->filePath();
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (false === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $grids = [];
        foreach ($decoded as $item) {
            $grid = $this->sanitize($item);
            if (null !== $grid) {
                $grids[] = $grid;
            }
        }

        return $grids;
    }

    /**
     * @param list<int> $numbers
     * @param list<int> $stars
     *
     * @throws DuplicateGridException when this grid is already saved
     * @throws \InvalidArgumentException when the grid is not valid for this game
     */
    public function add(array $numbers, array $stars): SavedGrid
    {
        $grid = Grid::multiple($this->game, $numbers, $stars);

        return $this->mutate(static function (array $all) use ($grid): array {
            foreach ($all as $existing) {
                if ($existing->grid->signature() === $grid->signature()) {
                    throw new DuplicateGridException($existing->id);
                }
            }
            $saved = new SavedGrid(self::nextId($all), $grid, new \DateTimeImmutable('today'), null);

            return [[...$all, $saved], $saved];
        });
    }

    /**
     * Saves several grids in one write. Grids already saved (or repeated in
     * the batch) are skipped.
     *
     * @param list<Grid> $grids
     *
     * @return list<SavedGrid> the grids actually added
     *
     * @throws \InvalidArgumentException when a grid is not valid for this game
     */
    public function addMany(array $grids): array
    {
        $grids = array_map(fn (Grid $g): Grid => Grid::multiple($this->game, $g->main, $g->bonus), $grids);

        return $this->mutate(static function (array $all) use ($grids): array {
            $known = [];
            foreach ($all as $existing) {
                $known[$existing->grid->signature()] = true;
            }
            $added = [];
            foreach ($grids as $grid) {
                if (isset($known[$grid->signature()])) {
                    continue;
                }
                $known[$grid->signature()] = true;
                $saved = new SavedGrid(self::nextId($all), $grid, new \DateTimeImmutable('today'), null);
                $all[] = $saved;
                $added[] = $saved;
            }

            return [$all, $added];
        });
    }

    public function remove(int $id): bool
    {
        return $this->mutate(static function (array $all) use ($id): array {
            $kept = array_values(array_filter($all, static fn (SavedGrid $g): bool => $g->id !== $id));

            return [$kept, \count($kept) !== \count($all)];
        });
    }

    /**
     * Pure report: checks every grid against the draws it has not been
     * checked against yet (every draw since its creation the first time).
     * Does not modify anything: call markChecked() once the report is shown.
     *
     * @param list<Draw> $draws
     *
     * @return list<array{
     *     grid: SavedGrid,
     *     results: list<array{date: string, matchedMain: int, matchedBonus: int, rank: ?int, wins: array<int, int>, amount: float}>
     * }> rank: the best tier won (a multiple grid can win several, see wins)
     */
    public function findWins(array $draws, PrizeTable $prizeTable): array
    {
        $report = [];

        foreach ($this->all() as $saved) {
            $results = [];

            foreach ($draws as $draw) {
                if (!$this->isPending($saved, $draw)) {
                    continue;
                }

                $matches = $saved->grid->matches($draw->grid);
                $result = $prizeTable->ticketResult($saved->grid, $draw->grid);

                if (null !== $result['best'] || $matches['main'] + $matches['bonus'] >= 2) {
                    $results[] = [
                        'date' => $draw->date->format('d/m/Y'),
                        'matchedMain' => $matches['main'],
                        'matchedBonus' => $matches['bonus'],
                        'rank' => $result['best'],
                        'wins' => $result['wins'],
                        'amount' => $result['amount'],
                    ];
                }
            }

            $report[] = ['grid' => $saved, 'results' => $results];
        }

        return $report;
    }

    /**
     * Every draw held since the grid was saved, newest first, with the
     * matches and the prize tier (if any).
     *
     * @param list<Draw> $draws sorted oldest to newest
     *
     * @return list<array{draw: Draw, matchedMain: int, matchedBonus: int, rank: ?int, wins: array<int, int>, amount: float}>
     *                                                                                                                        rank: the best tier won (a multiple grid can win several, see wins)
     */
    public function history(SavedGrid $saved, array $draws, PrizeTable $prizeTable): array
    {
        $history = [];
        foreach ($draws as $draw) {
            if ($draw->date < $saved->createdAt) {
                continue;
            }
            $matches = $saved->grid->matches($draw->grid);
            $result = $prizeTable->ticketResult($saved->grid, $draw->grid);
            $history[] = [
                'draw' => $draw,
                'matchedMain' => $matches['main'],
                'matchedBonus' => $matches['bonus'],
                'rank' => $result['best'],
                'wins' => $result['wins'],
                'amount' => $result['amount'],
            ];
        }

        return array_reverse($history);
    }

    /**
     * Records that every grid has been checked up to $lastDraw, the most
     * recent draw of the report. Draws published later, even for an earlier
     * date, are still reported next time: results are often published late.
     */
    public function markChecked(Draw $lastDraw): void
    {
        $this->mutate(static function (array $all) use ($lastDraw): array {
            $grids = array_map(
                static fn (SavedGrid $g): SavedGrid => null !== $g->lastDrawChecked && $g->lastDrawChecked >= $lastDraw->date
                    ? $g
                    : new SavedGrid($g->id, $g->grid, $g->createdAt, $lastDraw->date),
                $all,
            );

            return [$grids, null];
        });
    }

    /**
     * Not checked yet: held since the grid was saved, and after the last
     * draw it was checked against.
     */
    private function isPending(SavedGrid $saved, Draw $draw): bool
    {
        return $draw->date >= $saved->createdAt
            && (null === $saved->lastDrawChecked || $draw->date > $saved->lastDrawChecked);
    }

    /**
     * Runs a read-modify-write under an exclusive lock.
     *
     * @template T
     *
     * @param callable(list<SavedGrid>): array{0: list<SavedGrid>, 1: T} $change returns the new grids and a result
     *
     * @return T
     */
    private function mutate(callable $change): mixed
    {
        $lock = fopen($this->filePath().'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the grids file.');
        }

        try {
            $before = $this->all();
            [$after, $result] = $change($before);
            if ($after !== $before) {
                $this->write($after);
            }

            return $result;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<SavedGrid> $grids
     */
    private static function nextId(array $grids): int
    {
        return 1 + array_reduce($grids, static fn (int $max, SavedGrid $g): int => max($max, $g->id), 0);
    }

    private function filePath(): string
    {
        $dir = $this->dataDir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create "%s".', $dir));
        }

        return $dir.'/my-grids.json';
    }

    /**
     * @param list<SavedGrid> $grids
     */
    private function write(array $grids): void
    {
        $payload = array_map(static fn (SavedGrid $g): array => [
            'id' => $g->id,
            'numbers' => $g->grid->main,
            'stars' => $g->grid->bonus,
            'createdAt' => $g->createdAt->format('Y-m-d'),
            'lastDrawChecked' => $g->lastDrawChecked?->format('Y-m-d'),
        ], $grids);

        $file = $this->filePath();
        $tmp = $file.'.tmp';

        if (false === file_put_contents($tmp, json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT))) {
            throw new \RuntimeException('Unable to write the grids file.');
        }
        if (!rename($tmp, $file)) {
            throw new \RuntimeException('Unable to write the grids file.');
        }
    }

    private function sanitize(mixed $item): ?SavedGrid
    {
        if (!\is_array($item) || !isset($item['id'], $item['numbers'], $item['stars'], $item['createdAt'])
            || !is_numeric($item['id']) || !\is_array($item['numbers']) || !\is_array($item['stars']) || !\is_string($item['createdAt'])) {
            return null;
        }

        try {
            $grid = Grid::multiple($this->game, $item['numbers'], $item['stars']);
            $createdAt = new \DateTimeImmutable($item['createdAt']);
            $lastDrawChecked = match (true) {
                isset($item['lastDrawChecked']) && \is_string($item['lastDrawChecked']) => new \DateTimeImmutable($item['lastDrawChecked']),
                // Older files hold the day of the last check, and every draw from
                // that day on was still pending: the day before is equivalent.
                isset($item['lastCheckedAt']) && \is_string($item['lastCheckedAt']) => new \DateTimeImmutable($item['lastCheckedAt'])->modify('-1 day'),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }

        return new SavedGrid((int) $item['id'], $grid, $createdAt, $lastDrawChecked);
    }
}
