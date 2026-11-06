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
     * Raw values are normalized (cast to int, deduplicated, sorted) and
     * validated against the game's matrix.
     *
     * @param array<mixed> $main
     * @param array<mixed> $bonus
     */
    public static function of(LotteryGame $game, array $main, array $bonus): self
    {
        $main = self::normalize($main);
        $bonus = self::normalize($bonus);

        if ([] === $main || [] === $bonus) {
            throw new \InvalidArgumentException('A grid cannot be empty.');
        }
        if (\count($main) !== $game->mainPicked()) {
            throw new \InvalidArgumentException(\sprintf('%s requires %d distinct main numbers, got %d.', $game->name(), $game->mainPicked(), \count($main)));
        }
        if (\count($bonus) !== $game->bonusPicked()) {
            throw new \InvalidArgumentException(\sprintf('%s requires %d distinct bonus numbers, got %d.', $game->name(), $game->bonusPicked(), \count($bonus)));
        }
        foreach ($main as $n) {
            if ($n < 1 || $n > $game->mainPool()) {
                throw new \InvalidArgumentException(\sprintf('Main number out of range [1-%d]: %d.', $game->mainPool(), $n));
            }
        }
        foreach ($bonus as $n) {
            if ($n < 1 || $n > $game->bonusPool()) {
                throw new \InvalidArgumentException(\sprintf('Bonus number out of range [1-%d]: %d.', $game->bonusPool(), $n));
            }
        }

        return new self($main, $bonus);
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
