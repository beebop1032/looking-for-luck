<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\PrizeTable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "My grids": local storage (JSON under var/euromillions/) and checking
 * against draws.
 *
 * Security: atomic writes, content is systematically revalidated on read
 * (ranges, types) — a hand-edited file cannot inject invalid data into the
 * application.
 */
final class GridStore
{
    public function __construct(
        private readonly EuromillionsGame $game,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
     */
    public function add(array $numbers, array $stars): SavedGrid
    {
        $grid = Grid::of($this->game, $numbers, $stars);

        $all = $this->all();
        foreach ($all as $existing) {
            if ($existing->grid->signature() === $grid->signature()) {
                throw new \InvalidArgumentException(\sprintf('This grid is already saved as #%d.', $existing->id));
            }
        }

        $nextId = 1 + array_reduce($all, static fn (int $max, SavedGrid $g): int => max($max, $g->id), 0);

        $saved = new SavedGrid($nextId, $grid, new \DateTimeImmutable('today'), null);

        $all[] = $saved;
        $this->write($all);

        return $saved;
    }

    public function remove(int $id): bool
    {
        $grids = $this->all();
        $filtered = array_values(array_filter($grids, static fn (SavedGrid $g): bool => $g->id !== $id));

        if (\count($filtered) === \count($grids)) {
            return false;
        }

        $this->write($filtered);

        return true;
    }

    /**
     * Pure report: checks every grid against the draws held since its last
     * check (or its creation). Does not modify anything — call markChecked()
     * once the report has been shown.
     *
     * @param list<Draw> $draws
     *
     * @return list<array{
     *     grid: SavedGrid,
     *     results: list<array{date: string, matchedMain: int, matchedBonus: int, rank: ?int}>
     * }>
     */
    public function findWins(array $draws, PrizeTable $prizeTable): array
    {
        $report = [];

        foreach ($this->all() as $saved) {
            $since = $saved->lastCheckedAt ?? $saved->createdAt;
            $results = [];

            foreach ($draws as $draw) {
                if ($draw->date < $since) {
                    continue;
                }

                $matches = $saved->grid->matches($draw->grid);
                $rank = $prizeTable->rankFor($matches['main'], $matches['bonus']);

                if (null !== $rank || $matches['main'] + $matches['bonus'] >= 2) {
                    $results[] = [
                        'date' => $draw->date->format('d/m/Y'),
                        'matchedMain' => $matches['main'],
                        'matchedBonus' => $matches['bonus'],
                        'rank' => $rank,
                    ];
                }
            }

            $report[] = ['grid' => $saved, 'results' => $results];
        }

        return $report;
    }

    /**
     * Records today as the last check date for every grid.
     */
    public function markChecked(): void
    {
        $today = new \DateTimeImmutable('today');
        $grids = array_map(
            static fn (SavedGrid $g): SavedGrid => new SavedGrid($g->id, $g->grid, $g->createdAt, $today),
            $this->all(),
        );

        $this->write($grids);
    }

    private function filePath(): string
    {
        $dir = $this->projectDir.'/var/euromillions';
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
            'lastCheckedAt' => $g->lastCheckedAt?->format('Y-m-d'),
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
            $grid = Grid::of($this->game, $item['numbers'], $item['stars']);
            $createdAt = new \DateTimeImmutable($item['createdAt']);
            $lastCheckedAt = isset($item['lastCheckedAt']) && \is_string($item['lastCheckedAt'])
                ? new \DateTimeImmutable($item['lastCheckedAt'])
                : null;
        } catch (\Throwable) {
            return null;
        }

        return new SavedGrid((int) $item['id'], $grid, $createdAt, $lastCheckedAt);
    }
}
