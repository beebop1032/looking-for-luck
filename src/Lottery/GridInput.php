<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Parses a grid typed by a human: main numbers, a "+", then bonus numbers,
 * e.g. "1 9 23 34 48 + 3 7" for EuroMillions (commas accepted).
 */
final class GridInput
{
    /** EuroMillions example, kept for the CLI help texts. */
    public const EXAMPLE = '1 9 23 34 48 + 3 7';

    public function __construct(private readonly LotteryGame $game)
    {
    }

    /**
     * @throws \InvalidArgumentException with a human-readable message
     */
    public function parse(string $value, bool $allowMultiple = false): Grid
    {
        [$mainMax, $bonusMax] = $allowMultiple ? [$this->maxMain(), $this->maxBonus()] : [$this->game->mainPicked(), $this->game->bonusPicked()];
        $pattern = \sprintf(
            '/^\s*(\d{1,2}(?:[\s,]+\d{1,2}){%d,%d})\s*\+\s*(\d{1,2}(?:[\s,]+\d{1,2}){%d,%d})\s*$/',
            $this->game->mainPicked() - 1,
            $mainMax - 1,
            $this->game->bonusPicked() - 1,
            $bonusMax - 1,
        );
        if (1 !== preg_match($pattern, $value, $m)) {
            throw new \InvalidArgumentException(\sprintf('Invalid format. Expected %d numbers, a "+", then %d %s. Example: "%s"', $this->game->mainPicked(), $this->game->bonusPicked(), strtolower($this->game->bonusLabel()), $this->example()));
        }

        $numbers = array_map(intval(...), preg_split('/[\s,]+/', trim($m[1])) ?: []);
        $stars = array_map(intval(...), preg_split('/[\s,]+/', trim($m[2])) ?: []);

        return $allowMultiple ? Grid::multiple($this->game, $numbers, $stars) : Grid::of($this->game, $numbers, $stars);
    }

    /** Most numbers a multiple grid may tick (EuroMillions: 10). */
    public function maxMain(): int
    {
        return min($this->game->mainPool(), $this->game->mainPicked() + 5);
    }

    /** Most bonus numbers a multiple grid may tick (EuroMillions: 12). */
    public function maxBonus(): int
    {
        return $this->game->bonusPool();
    }

    /**
     * A valid example for this game, e.g. "3 14 25 36 47 + 2 9".
     */
    public function example(): string
    {
        $spread = static function (int $picked, int $pool): string {
            $numbers = [];
            for ($i = 0; $i < $picked; ++$i) {
                $numbers[] = (int) round(1 + ($pool - 1) * ($i + .3) / $picked);
            }

            return implode(' ', array_values(array_unique($numbers)));
        };

        return $spread($this->game->mainPicked(), $this->game->mainPool()).' + '.$spread($this->game->bonusPicked(), $this->game->bonusPool());
    }

    /**
     * A regular expression (for an HTML pattern attribute) matching the format.
     */
    public function htmlPattern(bool $allowMultiple = false): string
    {
        [$mainMax, $bonusMax] = $allowMultiple ? [$this->maxMain(), $this->maxBonus()] : [$this->game->mainPicked(), $this->game->bonusPicked()];

        return \sprintf(
            '\s*\d{1,2}([\s,]+\d{1,2}){%d,%d}\s*\+\s*\d{1,2}([\s,]+\d{1,2}){%d,%d}\s*',
            $this->game->mainPicked() - 1,
            $mainMax - 1,
            $this->game->bonusPicked() - 1,
            $bonusMax - 1,
        );
    }
}
