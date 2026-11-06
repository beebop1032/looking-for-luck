<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * "Anti-sharing" filter: rejects combinations that are statistically played
 * by many people. It changes NOTHING about the probability of winning —
 * every combination remains equally likely — but it reduces the risk of
 * having to split a big prize with other players who ticked the same grid.
 */
final class PopularityFilter
{
    /** Highest day-of-month: grids made only of birthdays stay at or below it. */
    private const BIRTHDAY_MAX = 31;

    /** @var array<string,true> */
    private readonly array $drawnSignatures;

    /**
     * @param list<Draw> $history past draws (to reject combinations that already came out)
     */
    public function __construct(
        private readonly LotteryGame $game,
        array $history = [],
    ) {
        $signatures = [];
        foreach ($history as $draw) {
            $signatures[$draw->grid->mainSignature()] = true;
        }
        $this->drawnSignatures = $signatures;
    }

    /**
     * @param list<Draw> $history
     */
    public function withHistory(array $history): self
    {
        return new self($this->game, $history);
    }

    /**
     * Reasons a combination gets rejected, empty if it passes the filter.
     *
     * @return list<string>
     */
    public function rejectionReasons(Grid $grid): array
    {
        $numbers = $grid->main;
        $reasons = [];

        if ($this->allBirthdayNumbers($numbers)) {
            $reasons[] = \sprintf('all numbers ≤ %d ("birthday" grids, heavily played)', self::BIRTHDAY_MAX);
        }
        if ($this->isArithmeticSequence($numbers)) {
            $reasons[] = 'arithmetic sequence (e.g. 5-10-15-20-25), a very popular pattern';
        }
        if ($this->hasLongConsecutiveRun($numbers)) {
            $reasons[] = 'four or more consecutive numbers';
        }
        if ($this->isVisualPattern($numbers)) {
            $reasons[] = 'visual pattern on the paper slip (column, row or diagonal)';
        }
        if ($this->shareCommonMultiple($numbers)) {
            $reasons[] = 'all numbers are multiples of the same value';
        }
        if (isset($this->drawnSignatures[$grid->mainSignature()])) {
            $reasons[] = 'combination already drawn in a past draw (often replayed)';
        }

        return $reasons;
    }

    public function isPopular(Grid $grid): bool
    {
        return [] !== $this->rejectionReasons($grid);
    }

    /**
     * @param non-empty-list<int> $numbers
     */
    private function allBirthdayNumbers(array $numbers): bool
    {
        return max($numbers) <= self::BIRTHDAY_MAX;
    }

    /**
     * @param list<int> $numbers
     */
    private function isArithmeticSequence(array $numbers): bool
    {
        if (\count($numbers) < 3) {
            return false;
        }

        $step = $numbers[1] - $numbers[0];
        for ($i = 2, $n = \count($numbers); $i < $n; ++$i) {
            if ($numbers[$i] - $numbers[$i - 1] !== $step) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $numbers
     */
    private function hasLongConsecutiveRun(array $numbers): bool
    {
        $run = 1;
        for ($i = 1, $n = \count($numbers); $i < $n; ++$i) {
            $run = ($numbers[$i - 1] + 1 === $numbers[$i]) ? $run + 1 : 1;
            if ($run >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paper slip: numbers laid out in columns (5 wide for EuroMillions).
     * Detected patterns: all in the same column, all in the same row
     * (only possible for full rows), perfect diagonal.
     *
     * @param list<int> $numbers
     */
    private function isVisualPattern(array $numbers): bool
    {
        $slipColumns = $this->game->slipColumns();

        $columns = [];
        $rows = [];
        foreach ($numbers as $number) {
            $columns[] = ($number - 1) % $slipColumns;
            $rows[] = intdiv($number - 1, $slipColumns);
        }

        if (1 === \count(array_unique($columns)) || 1 === \count(array_unique($rows))) {
            return true;
        }

        // Diagonal: consecutive rows with columns progressing by ±1.
        $isDiagonal = true;
        for ($i = 1, $n = \count($numbers); $i < $n; ++$i) {
            if ($rows[$i - 1] + 1 !== $rows[$i] || 1 !== abs($columns[$i] - $columns[$i - 1])) {
                $isDiagonal = false;
                break;
            }
        }

        return $isDiagonal;
    }

    /**
     * @param list<int> $numbers
     */
    private function shareCommonMultiple(array $numbers): bool
    {
        $gcd = array_reduce($numbers, self::gcd(...), 0);

        return $gcd >= 2;
    }

    private static function gcd(int $a, int $b): int
    {
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }
}
