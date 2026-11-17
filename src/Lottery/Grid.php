<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * A played (or drawn) grid: main numbers + bonus numbers, validated against
 * a game's matrix, always sorted.
 */
final class Grid
{
    /**
     * @param non-empty-list<int> $main
     * @param non-empty-list<int> $bonus
     */
    private function __construct(
        public readonly array $main,
        public readonly array $bonus,
    ) {
    }

    /**
     * A simple grid. Raw values are normalized (cast to int, deduplicated,
     * sorted) and validated against the game's matrix.
     *
     * @param array<mixed> $main
     * @param array<mixed> $bonus
     */
    public static function of(LotteryGame $game, array $main, array $bonus): self
    {
        return self::validated($game, $main, $bonus, $game->mainPicked(), $game->bonusPicked());
    }

    /**
     * A ticket that may tick more numbers than a simple grid (a "multiple"
     * grid), e.g. 7 numbers and 3 stars. A simple grid is valid too.
     *
     * @param array<mixed> $main
     * @param array<mixed> $bonus
     */
    public static function multiple(LotteryGame $game, array $main, array $bonus): self
    {
        return self::validated($game, $main, $bonus, $game->mainPool(), $game->bonusPool());
    }

    /**
     * @param array<mixed> $main
     * @param array<mixed> $bonus
     * @param int $maxMain most main numbers allowed (at least mainPicked)
     * @param int $maxBonus most bonus numbers allowed (at least bonusPicked)
     */
    private static function validated(LotteryGame $game, array $main, array $bonus, int $maxMain, int $maxBonus): self
    {
        $main = self::normalize($main);
        $bonus = self::normalize($bonus);

        if ([] === $main || [] === $bonus) {
            throw new \InvalidArgumentException('A grid cannot be empty.');
        }
        self::checkCount($game, 'main', \count($main), $game->mainPicked(), $maxMain);
        self::checkCount($game, 'bonus', \count($bonus), $game->bonusPicked(), $maxBonus);
        self::checkRange('Main', $main, $game->mainPool());
        self::checkRange('Bonus', $bonus, $game->bonusPool());

        return new self($main, $bonus);
    }

    private static function checkCount(LotteryGame $game, string $kind, int $count, int $min, int $max): void
    {
        if ($count < $min || $count > $max) {
            throw new \InvalidArgumentException($min === $max ? \sprintf('%s requires %d distinct %s numbers, got %d.', $game->name(), $min, $kind, $count) : \sprintf('%s needs %d to %d distinct %s numbers, got %d.', $game->name(), $min, $max, $kind, $count));
        }
    }

    /**
     * @param list<int> $numbers
     */
    private static function checkRange(string $kind, array $numbers, int $pool): void
    {
        foreach ($numbers as $n) {
            if ($n < 1 || $n > $pool) {
                throw new \InvalidArgumentException(\sprintf('%s number out of range [1-%d]: %d.', $kind, $pool, $n));
            }
        }
    }

    /**
     * How many simple grids this ticket contains (1 for a simple grid).
     */
    public function combinations(LotteryGame $game): int
    {
        return (int) round(PrizeTable::choose(\count($this->main), $game->mainPicked()) * PrizeTable::choose(\count($this->bonus), $game->bonusPicked()));
    }

    public function isMultiple(LotteryGame $game): bool
    {
        return \count($this->main) > $game->mainPicked() || \count($this->bonus) > $game->bonusPicked();
    }

    public function signature(): string
    {
        return implode('-', $this->main).'|'.implode('-', $this->bonus);
    }

    public function mainSignature(): string
    {
        return implode('-', $this->main);
    }

    /**
     * How many main and bonus numbers this grid shares with another.
     *
     * @return array{main: int, bonus: int}
     */
    public function matches(self $other): array
    {
        return [
            'main' => \count(array_intersect($this->main, $other->main)),
            'bonus' => \count(array_intersect($this->bonus, $other->bonus)),
        ];
    }

    /**
     * Human-friendly rendering, e.g. "04 23 29 37 44 + 02 09".
     */
    public function format(): string
    {
        return $this->formatMain().' + '.$this->formatBonus();
    }

    public function formatMain(): string
    {
        return self::formatNumbers($this->main);
    }

    public function formatBonus(): string
    {
        return self::formatNumbers($this->bonus);
    }

    /**
     * @param list<int> $numbers
     */
    private static function formatNumbers(array $numbers): string
    {
        $pad = static fn (int $n): string => str_pad((string) $n, 2, '0', \STR_PAD_LEFT);

        return implode(' ', array_map($pad, $numbers));
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private static function normalize(array $values): array
    {
        $values = array_values(array_unique(array_map(
            static fn (mixed $value): int => \is_scalar($value) ? (int) $value : 0,
            $values,
        )));
        sort($values);

        return $values;
    }
}
