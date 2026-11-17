<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Downloads the latest draws of one lottery and checks every saved grid
 * against them. Shared by the scheduled job (app:sync, run by cron in the
 * Docker image) and the "Refresh" button of the web interface.
 */
final class Synchronizer
{
    public function __construct(
        private readonly LotteryGame $game,
        private readonly DrawSource $source,
        private readonly GridStore $store,
        private readonly PrizeTable $prizeTable,
        private readonly SyncStatus $status,
    ) {
    }

    /**
     * Is a draw that we do not know about yet expected by now? True when the
     * last scheduled draw (if held in the past three days, results take a
     * while to be published) is more recent than the last known draw.
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $previous = DrawSchedule::previousBefore($this->game, $now);
        if ($now->getTimestamp() - $previous->getTimestamp() > 3 * 86_400) {
            return false;
        }

        $latest = $this->status->last()['latestDraw'] ?? null;
        if (null === $latest) {
            return true;
        }

        return $latest < $previous->format('Y-m-d');
    }

    /**
     * @return array{
     *     draws: int,
     *     latest: ?Draw,
     *     report: list<array{grid: SavedGrid, results: list<array{date: string, matchedMain: int, matchedBonus: int, rank: ?int, wins: array<int, int>, amount: float}>}>,
     *     wins: list<array{grid: int, rank: int, date: string, main: int, bonus: int}>
     * }
     *
     * @throws \RuntimeException when no draw history is available at all
     */
    public function sync(): array
    {
        try {
            $draws = $this->source->refresh();
        } catch (\RuntimeException $e) {
            $this->status->record(false, $e->getMessage(), null);

            throw $e;
        }

        $latest = [] === $draws ? null : $draws[array_key_last($draws)];

        // A new draw also means a new announced jackpot.
        if ($this->source instanceof NextJackpotSource) {
            $this->source->nextJackpot(true);
        }

        $report = $this->store->findWins($draws, $this->prizeTable);
        $wins = [];
        foreach ($report as $entry) {
            foreach ($entry['results'] as $result) {
                if (null !== $result['rank']) {
                    $wins[] = [
                        'grid' => $entry['grid']->id,
                        'rank' => $result['rank'],
                        'date' => $result['date'],
                        'main' => $result['matchedMain'],
                        'bonus' => $result['matchedBonus'],
                    ];
                }
            }
        }
        if (null !== $latest) {
            $this->store->markChecked($latest);
        }

        $this->status->record(
            true,
            \sprintf('%d draws known, %d grid(s) checked.', \count($draws), \count($report)),
            $latest?->date->format('Y-m-d'),
            array_map(self::describe(...), $wins),
        );

        return ['draws' => \count($draws), 'latest' => $latest, 'report' => $report, 'wins' => $wins];
    }

    /**
     * @param array{grid: int, rank: int, date: string, main: int, bonus: int} $win
     */
    public static function describe(array $win): string
    {
        return \sprintf('Grid #%d won tier %d on %s (%d + %d)', $win['grid'], $win['rank'], $win['date'], $win['main'], $win['bonus']);
    }
}
